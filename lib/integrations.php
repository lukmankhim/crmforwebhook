<?php
declare(strict_types=1);

// Framework integrasi: tiap provider mendaftarkan validasi akun, tes koneksi, dan sync.
// Fase 4 (Google Ads, Meta) menambah entri di sini tanpa mengubah runner.

const SYNC_INTERVAL_MINUTES = 60;
const SYNC_BACKFILL_DAYS = 90;
const SYNC_ALERT_AFTER_FAILURES = 2;

function integration_providers(): array
{
    return [
        'ga4' => [
            'label' => 'Google Analytics 4',
            'account_label' => 'Property ID',
            'account_help' => 'Angka di GA4: Admin, Property details. Contoh 123456789.',
            'grant_help' => 'Di GA4: Admin, Property access management, tambah email di bawah sebagai Viewer.',
            'normalize' => fn(string $v) => preg_match('/^(?:properties\/)?(\d{5,15})$/', trim($v), $m) ? $m[1] : null,
            'lag_days' => 3,
            'test' => 'ga4_test',
            'sync' => 'ga4_sync',
        ],
        'gsc' => [
            'label' => 'Google Search Console',
            'account_label' => 'Properti Search Console',
            'account_help' => 'Persis seperti di Search Console: sc-domain:contoh.com atau https://www.contoh.com/',
            'grant_help' => 'Di Search Console: Settings, Users and permissions, tambah email di bawah (Restricted cukup).',
            'normalize' => function (string $v): ?string {
                $v = trim($v);
                if (preg_match('/^sc-domain:[a-z0-9.-]+\.[a-z]{2,}$/i', $v)) {
                    return strtolower($v);
                }
                return preg_match('#^https?://[^\s/]+/.*$#i', $v) ? $v : null;
            },
            'lag_days' => 5,
            'test' => 'gsc_test',
            'sync' => 'gsc_sync',
        ],
    ];
}

function tenant_integrations(): array
{
    $out = [];
    foreach (q_tenant('SELECT * FROM integrations WHERE tenant_id = :tid') as $row) {
        $out[$row['provider']] = $row;
    }
    return $out;
}

/** Rentang tanggal sync dalam zona waktu tenant: backfill 90 hari pertama kali, lalu beberapa hari terakhir. */
function sync_range(array $integration, array $provider): array
{
    $today = new DateTimeImmutable('today', tenant_tz());
    $days = $integration['backfilled_at'] === null ? SYNC_BACKFILL_DAYS - 1 : $provider['lag_days'];
    return [$today->modify("-$days days")->format('Y-m-d'), $today->format('Y-m-d')];
}

/** Jalankan satu sync. Dipanggil dari cron, tidak pernah dari request web. */
function integration_sync(array $integration): array
{
    set_tenant_context((int) $integration['tenant_id']);
    $provider = integration_providers()[$integration['provider']] ?? null;
    [$from, $to] = $provider ? sync_range($integration, $provider) : [null, null];
    q_tenant('INSERT INTO sync_runs (tenant_id, provider, date_from, date_to) VALUES (:tid, :p, :f, :t)', [
        'p' => $integration['provider'], 'f' => $from, 't' => $to,
    ]);
    $runId = (int) db()->lastInsertId();
    try {
        if (!$provider) {
            throw new RuntimeException('Provider belum didukung.');
        }
        $rows = ($provider['sync'])($integration, $from, $to);
        q_tenant("UPDATE sync_runs SET status = 'success', finished_at = UTC_TIMESTAMP(), `rows` = :r WHERE tenant_id = :tid AND id = :id", ['r' => $rows, 'id' => $runId]);
        q_tenant('UPDATE integrations SET last_sync_at = UTC_TIMESTAMP(), last_success_at = UTC_TIMESTAMP(), last_error = NULL,
                  consecutive_failures = 0, sync_requested_at = NULL, backfilled_at = COALESCE(backfilled_at, UTC_TIMESTAMP())
                  WHERE tenant_id = :tid AND id = :id', ['id' => $integration['id']]);
        return ['ok' => true, 'rows' => $rows];
    } catch (Throwable $e) {
        $error = mb_substr($e->getMessage(), 0, 480);
        q_tenant("UPDATE sync_runs SET status = 'failed', finished_at = UTC_TIMESTAMP(), error = :e WHERE tenant_id = :tid AND id = :id", ['e' => $error, 'id' => $runId]);
        q_tenant('UPDATE integrations SET last_sync_at = UTC_TIMESTAMP(), last_error = :e, consecutive_failures = consecutive_failures + 1,
                  sync_requested_at = NULL WHERE tenant_id = :tid AND id = :id', ['e' => $error, 'id' => $integration['id']]);
        $failures = (int) $integration['consecutive_failures'] + 1;
        if ($failures === SYNC_ALERT_AFTER_FAILURES) {
            $tenant = current_tenant();
            notify_telegram("Sync gagal {$failures}x berturut-turut\nTenant: {$tenant['name']}\nIntegrasi: " . ($provider['label'] ?? $integration['provider']) . "\n{$error}");
        }
        app_log('sync.log', "tenant={$integration['tenant_id']} provider={$integration['provider']} error={$error}");
        return ['ok' => false, 'error' => $error];
    } finally {
        set_tenant_context(null);
    }
}

/** Upsert massal ke tabel harian. $rows sudah berisi dim_hash. Kolom metrik ditimpa dengan nilai terbaru. */
function upsert_daily(string $table, array $columns, array $metrics, array $rows): int
{
    $allowed = ['site_daily', 'search_daily'];
    if (!in_array($table, $allowed, true)) {
        throw new LogicException('Tabel tidak dikenal');
    }
    $count = 0;
    foreach (array_chunk($rows, 500) as $chunk) {
        $values = [];
        $params = [];
        foreach ($chunk as $i => $row) {
            $ph = [':tid'];
            foreach ($columns as $c) {
                $ph[] = ":{$c}_{$i}";
                $params["{$c}_{$i}"] = $row[$c];
            }
            $values[] = '(' . implode(', ', $ph) . ')';
        }
        $updates = implode(', ', array_map(fn($m) => "$m = VALUES($m)", $metrics));
        q_tenant("INSERT INTO $table (tenant_id, " . implode(', ', $columns) . ') VALUES ' . implode(', ', $values)
            . " ON DUPLICATE KEY UPDATE $updates", $params);
        $count += count($chunk);
    }
    return $count;
}

function ymd_from_compact(string $d): string
{
    return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $d, $m) ? "$m[1]-$m[2]-$m[3]" : $d;
}

// ---------------------------------------------------------------- GA4

function ga4_report(array $integration, array $body): array
{
    $url = google_endpoint('ga4') . '/properties/' . rawurlencode($integration['account_ref']) . ':runReport';
    return google_call($integration['credential_ref'], 'POST', $url, $body);
}

function ga4_test(array $integration): string
{
    $yesterday = (new DateTimeImmutable('yesterday', tenant_tz()))->format('Y-m-d');
    $res = ga4_report($integration, [
        'dateRanges' => [['startDate' => $yesterday, 'endDate' => $yesterday]],
        'metrics' => [['name' => 'sessions']],
        'limit' => 1,
    ]);
    $sessions = (int) ($res['rows'][0]['metricValues'][0]['value'] ?? 0);
    return "Tersambung. Sesi kemarin menurut GA4: $sessions.";
}

function ga4_sync(array $integration, string $from, string $to): int
{
    $offset = 0;
    $limit = 10000;
    $total = 0;
    do {
        $res = ga4_report($integration, [
            'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
            'dimensions' => array_map(fn($n) => ['name' => $n], ['date', 'sessionSource', 'sessionMedium', 'sessionCampaignName', 'landingPage']),
            'metrics' => array_map(fn($n) => ['name' => $n], ['sessions', 'totalUsers', 'keyEvents']),
            'limit' => $limit,
            'offset' => $offset,
        ]);
        $rows = [];
        foreach ($res['rows'] ?? [] as $r) {
            $d = array_map(fn($x) => (string) ($x['value'] ?? ''), $r['dimensionValues'] ?? []);
            $m = array_map(fn($x) => $x['value'] ?? '0', $r['metricValues'] ?? []);
            $row = [
                'date' => ymd_from_compact($d[0] ?? ''),
                'source' => mb_substr($d[1] ?? '', 0, 150),
                'medium' => mb_substr($d[2] ?? '', 0, 100),
                'campaign' => mb_substr($d[3] ?? '', 0, 200),
                'landing_page' => mb_substr($d[4] ?? '', 0, 1000),
                'sessions' => (int) ($m[0] ?? 0),
                'users' => (int) ($m[1] ?? 0),
                'conversions' => round((float) ($m[2] ?? 0), 2),
            ];
            $row['dim_hash'] = sha1(implode("\x1f", [$row['source'], $row['medium'], $row['campaign'], $row['landing_page']]));
            $rows[] = $row;
        }
        $total += upsert_daily('site_daily', ['date', 'source', 'medium', 'campaign', 'landing_page', 'dim_hash', 'sessions', 'users', 'conversions'],
            ['sessions', 'users', 'conversions'], $rows);
        $offset += $limit;
    } while ($offset < (int) ($res['rowCount'] ?? 0));
    return $total;
}

// ---------------------------------------------------------------- Search Console

function gsc_site_url(array $integration): string
{
    return google_endpoint('gsc') . '/sites/' . rawurlencode($integration['account_ref']);
}

function gsc_test(array $integration): string
{
    $res = google_call($integration['credential_ref'], 'GET', gsc_site_url($integration));
    $level = $res['permissionLevel'] ?? 'tidak diketahui';
    if ($level === 'siteUnverifiedUser') {
        throw new RuntimeException('Service account belum punya akses ke properti ini (siteUnverifiedUser).');
    }
    return "Tersambung. Level akses: $level.";
}

/**
 * Dua tarikan: per halaman (query kosong) untuk total yang akurat, dan per halaman + query.
 * Google menyembunyikan query anonim, jadi jumlah klik per query selalu lebih kecil dari total halaman.
 */
function gsc_sync(array $integration, string $from, string $to): int
{
    $total = 0;
    foreach ([['date', 'page'], ['date', 'page', 'query']] as $dims) {
        $startRow = 0;
        $limit = 25000;
        do {
            $res = google_call($integration['credential_ref'], 'POST', gsc_site_url($integration) . '/searchAnalytics/query', [
                'startDate' => $from,
                'endDate' => $to,
                'dimensions' => $dims,
                'rowLimit' => $limit,
                'startRow' => $startRow,
            ]);
            $rows = [];
            foreach ($res['rows'] ?? [] as $r) {
                $keys = $r['keys'] ?? [];
                $row = [
                    'date' => (string) ($keys[0] ?? ''),
                    'page' => mb_substr((string) ($keys[1] ?? ''), 0, 1000),
                    'query' => mb_substr((string) ($keys[2] ?? ''), 0, 500),
                    'clicks' => (int) ($r['clicks'] ?? 0),
                    'impressions' => (int) ($r['impressions'] ?? 0),
                    'position' => round((float) ($r['position'] ?? 0), 2),
                ];
                $row['dim_hash'] = sha1($row['page'] . "\x1f" . $row['query']);
                $rows[] = $row;
            }
            $total += upsert_daily('search_daily', ['date', 'page', 'query', 'dim_hash', 'clicks', 'impressions', 'position'],
                ['clicks', 'impressions', 'position'], $rows);
            $startRow += $limit;
        } while (count($res['rows'] ?? []) === $limit);
    }
    return $total;
}
