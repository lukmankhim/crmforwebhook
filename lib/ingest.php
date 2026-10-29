<?php
declare(strict_types=1);

const INGEST_MAX_BODY = 65536;
const INGEST_MAX_ATTEMPTS = 5;
const INGEST_BACKOFF_MINUTES = [1, 5, 15, 60, 240];
const INGEST_RATE_PER_MINUTE = 120;
const CLICK_ID_FIELDS = ['ctwa_clid', 'fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid'];

/** Adapter yang tersedia. Mapping JSON diisi dari dashboard, tanpa deploy. */
function ingest_adapters(): array
{
    return [
        'generic' => [
            'label' => 'Generic JSON (Zapier, Make, n8n, form HTML, gateway WA)',
            'auth' => 'bearer',
            'mapping' => [
                'external_id_path' => 'id',
                'phone_path' => 'phone',
                'name_path' => 'name',
                'message_path' => 'message',
                'campaign_path' => 'utm_campaign',
                'landing_path' => 'landing_url',
                'referrer_path' => 'referrer',
                'click_id_paths' => ['gclid' => 'gclid', 'fbclid' => 'fbclid', 'gbraid' => 'gbraid', 'wbraid' => 'wbraid', 'ttclid' => 'ttclid', 'ctwa_clid' => 'ctwa_clid'],
            ],
        ],
        'qontak' => [
            'label' => 'Qontak',
            'auth' => 'bearer',
            'mapping' => [
                'external_id_path' => 'id',
                'phone_path' => 'customer.phone_number',
                'name_path' => 'customer.name',
                'message_path' => 'last_message',
                'campaign_path' => 'source.campaign.name',
                'click_id_paths' => ['ctwa_clid' => 'ctwa_clid', 'fbclid' => 'fbclid'],
                'landing_path' => 'landing_url',
                'time_path' => 'created_at',
            ],
        ],
        'google_lead_form' => [
            'label' => 'Google Ads Lead Form',
            'auth' => 'google_key',
            // Field dibaca langsung dari format resmi (user_column_data), mapping tidak dipakai.
            'mapping' => new stdClass(),
        ],
    ];
}

/** Ambil nilai dari array bersarang dengan path seperti "customer.phone" atau "items[0].id". */
function json_path_get(array $data, ?string $path): mixed
{
    if ($path === null || $path === '') {
        return null;
    }
    $path = preg_replace('/\[(\d+)\]/', '.$1', $path);
    $value = $data;
    foreach (explode('.', $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return is_scalar($value) ? trim((string) $value) : null;
}

/**
 * Ubah payload mentah jadi bentuk lead standar. Mengembalikan array dengan key 'error' kalau gagal.
 * Field stage, status, dan nominal dari luar sengaja tidak dibaca.
 */
function ingest_normalize(array $source, array $payload): array
{
    if ($source['adapter'] === 'google_lead_form') {
        $norm = ingest_normalize_google($payload);
    } else {
        $m = json_decode((string) $source['mapping_json'], true) ?: [];
        $norm = [
            'external_id' => json_path_get($payload, $m['external_id_path'] ?? null),
            'phone_raw' => json_path_get($payload, $m['phone_path'] ?? null),
            'name' => json_path_get($payload, $m['name_path'] ?? null),
            'message' => json_path_get($payload, $m['message_path'] ?? null),
            'campaign' => json_path_get($payload, $m['campaign_path'] ?? null),
            'landing_url' => json_path_get($payload, $m['landing_path'] ?? null),
            'referrer' => json_path_get($payload, $m['referrer_path'] ?? null),
            'ad_id' => json_path_get($payload, $m['ad_id_path'] ?? null),
            'user_agent' => json_path_get($payload, $m['user_agent_path'] ?? null),
        ];
        foreach (CLICK_ID_FIELDS as $f) {
            $norm[$f] = json_path_get($payload, $m['click_id_paths'][$f] ?? null);
        }
    }

    $norm['phone'] = normalize_phone($norm['phone_raw']);
    if ($norm['phone'] === null) {
        $norm['error'] = $norm['phone_raw'] === null || $norm['phone_raw'] === ''
            ? 'Nomor HP tidak ditemukan di payload (cek phone_path).'
            : 'Nomor HP tidak valid: ' . mb_substr($norm['phone_raw'], 0, 30);
    }
    $norm['src_tag'] = $norm['campaign'] ?? null;
    if ($norm['external_id'] !== null) {
        $norm['external_id'] = mb_substr($norm['external_id'], 0, 180);
    }
    return $norm;
}

function ingest_normalize_google(array $p): array
{
    $cols = [];
    foreach (($p['user_column_data'] ?? []) as $col) {
        if (is_array($col) && isset($col['column_id'])) {
            $cols[$col['column_id']] = (string) ($col['string_value'] ?? '');
        }
    }
    $name = $cols['FULL_NAME'] ?? trim(($cols['FIRST_NAME'] ?? '') . ' ' . ($cols['LAST_NAME'] ?? ''));
    $norm = [
        'external_id' => isset($p['lead_id']) ? (string) $p['lead_id'] : null,
        'phone_raw' => $cols['PHONE_NUMBER'] ?? null,
        'name' => $name !== '' ? $name : null,
        'message' => null,
        'campaign' => isset($p['campaign_id']) ? 'gads campaign ' . $p['campaign_id'] : null,
        'landing_url' => null,
        'referrer' => null,
        'ad_id' => isset($p['creative_id']) ? (string) $p['creative_id'] : null,
        'user_agent' => null,
    ];
    foreach (CLICK_ID_FIELDS as $f) {
        $norm[$f] = null;
    }
    $norm['gclid'] = isset($p['gcl_id']) ? (string) $p['gcl_id'] : null;
    return $norm;
}

function ingest_parse_body(string $body): ?array
{
    $type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($type, 'application/x-www-form-urlencoded')) {
        parse_str($body, $data);
        return is_array($data) ? $data : null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : null;
}

/** Endpoint POST /ingest.php?t=<tenant>&s=<source>. Simpan cepat, proses di worker. */
function ingest_handle_request(): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['error' => 'method_not_allowed'], 405);
    }
    $ip = client_ip();
    $slug = (string) ($_GET['t'] ?? '');
    $sourceSlug = (string) ($_GET['s'] ?? '');
    if (!preg_match('/^[a-z0-9-]{1,64}$/', $slug) || !preg_match('/^[a-z0-9-]{1,64}$/', $sourceSlug)) {
        json_response(['error' => 'bad_request'], 400);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > INGEST_MAX_BODY) {
        json_response(['error' => 'payload_too_large'], 413);
    }
    $body = (string) file_get_contents('php://input', false, null, 0, INGEST_MAX_BODY + 1);
    if (strlen($body) > INGEST_MAX_BODY) {
        json_response(['error' => 'payload_too_large'], 413);
    }

    $tenant = q("SELECT id FROM tenants WHERE slug = ? AND status = 'active'", [$slug])->fetch();
    if (!$tenant) {
        rate_limit('ingest-bad:' . $ip, 30, 60);
        json_response(['error' => 'unknown_tenant'], 404);
    }
    set_tenant_context((int) $tenant['id']);
    $source = q_tenant('SELECT * FROM ingest_sources WHERE tenant_id = :tid AND slug = :s AND active = 1', ['s' => $sourceSlug])->fetch();
    if (!$source) {
        json_response(['error' => 'unknown_source'], 404);
    }

    if ($source['auth_type'] !== 'google_key') {
        if (!rate_limit('ingest-ip:' . $ip, 600, 60)) {
            json_response(['error' => 'rate_limited'], 429);
        }
        $token = $source['auth_type'] === 'query' ? (string) ($_GET['k'] ?? '') : (string) bearer_token();
        $key = $token !== ''
            ? q_tenant("SELECT id FROM api_keys WHERE tenant_id = :tid AND key_hash = :h AND active = 1 AND FIND_IN_SET('ingest', scopes)", ['h' => token_hash($token)])->fetch()
            : false;
        if (!$key) {
            if (!rate_limit('ingest-bad:' . $ip, 30, 60)) {
                json_response(['error' => 'rate_limited'], 429);
            }
            json_response(['error' => 'unauthorized'], 401);
        }
        if (!rate_limit('ingest-key:' . $key['id'], INGEST_RATE_PER_MINUTE, 60)) {
            json_response(['error' => 'rate_limited'], 429);
        }
        q_tenant('UPDATE api_keys SET last_used_at = UTC_TIMESTAMP() WHERE tenant_id = :tid AND id = :id', ['id' => $key['id']]);
    } elseif (!rate_limit('ingest-src:' . $source['id'], INGEST_RATE_PER_MINUTE, 60)) {
        json_response(['error' => 'rate_limited'], 429);
    }

    $secret = decrypt_secret($source['secret_enc']);
    if ($source['signature_type'] === 'hmac_sha256') {
        $sent = (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '');
        $expected = 'sha256=' . hash_hmac('sha256', $body, (string) $secret);
        if ($secret === null || !hash_equals($expected, $sent)) {
            json_response(['error' => 'bad_signature'], 401);
        }
    }

    $payload = ingest_parse_body($body);
    if ($payload === null) {
        json_response(['error' => 'invalid_json'], 400);
    }
    if ($source['auth_type'] === 'google_key') {
        if ($secret === null || !hash_equals($secret, (string) ($payload['google_key'] ?? ''))) {
            json_response(['error' => 'unauthorized'], 401);
        }
    }

    $hash = hash('sha256', $body);
    $norm = ingest_normalize($source, $payload);
    if (isset($norm['error'])) {
        // Tetap disimpan (tanpa external_id) supaya mapping bisa diperbaiki dari contoh payload.
        q_tenant("INSERT INTO ingest_log (tenant_id, source, source_id, external_id, payload_hash, payload, status, error, ip)
                  VALUES (:tid, :s, :sid, NULL, :h, :p, 'rejected', :e, :ip)", [
            's' => $source['slug'], 'sid' => $source['id'], 'h' => $hash, 'p' => $body, 'e' => $norm['error'], 'ip' => $ip,
        ]);
        json_response(['error' => 'invalid_lead', 'detail' => $norm['error']], 422);
    }

    $externalId = $norm['external_id'] ?? ('h:' . $hash);
    try {
        q_tenant("INSERT INTO ingest_log (tenant_id, source, source_id, external_id, payload_hash, payload, status, ip)
                  VALUES (:tid, :s, :sid, :x, :h, :p, 'received', :ip)", [
            's' => $source['slug'], 'sid' => $source['id'], 'x' => $externalId, 'h' => $hash, 'p' => $body, 'ip' => $ip,
        ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            json_response(['status' => 'duplicate']);
        }
        throw $e;
    }
    json_response(['status' => 'received', 'id' => (int) db()->lastInsertId()]);
}

/** Diproses worker cron. Tenant context diset dari baris log, bukan dari payload. */
function ingest_process_row(array $row): void
{
    set_tenant_context((int) $row['tenant_id']);
    try {
        $source = q_tenant('SELECT * FROM ingest_sources WHERE tenant_id = :tid AND id = :id', ['id' => $row['source_id']])->fetch();
        if (!$source) {
            throw new RuntimeException('Sumber webhook sudah dihapus.');
        }
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            parse_str((string) $row['payload'], $payload);
        }
        if (!is_array($payload) || !$payload) {
            throw new RuntimeException('Payload tidak bisa dibaca.');
        }
        $norm = ingest_normalize($source, $payload);
        if (isset($norm['error'])) {
            throw new RuntimeException($norm['error']);
        }
        tx(function () use ($norm, $source, $row) {
            [$leadId] = lead_upsert_from_ingest($norm, $source);
            q_tenant("UPDATE ingest_log SET status = 'processed', lead_id = :l, error = NULL, next_retry_at = NULL,
                      processed_at = UTC_TIMESTAMP(), attempts = attempts + 1 WHERE tenant_id = :tid AND id = :id", [
                'l' => $leadId, 'id' => $row['id'],
            ]);
        });
    } catch (Throwable $e) {
        $attempts = (int) $row['attempts'] + 1;
        $error = mb_substr($e->getMessage(), 0, 480);
        if ($attempts >= INGEST_MAX_ATTEMPTS) {
            q_tenant("UPDATE ingest_log SET status = 'failed', attempts = :a, error = :e, next_retry_at = NULL
                      WHERE tenant_id = :tid AND id = :id", ['a' => $attempts, 'e' => $error, 'id' => $row['id']]);
            $tenant = current_tenant();
            notify_telegram("Ingest gagal permanen\nTenant: {$tenant['name']}\nSumber: {$row['source']}\nLog #{$row['id']}: {$error}");
        } else {
            $delay = INGEST_BACKOFF_MINUTES[$attempts - 1] ?? 240;
            q_tenant("UPDATE ingest_log SET status = 'retry', attempts = :a, error = :e,
                      next_retry_at = UTC_TIMESTAMP() + INTERVAL :d MINUTE WHERE tenant_id = :tid AND id = :id", [
                'a' => $attempts, 'e' => $error, 'd' => $delay, 'id' => $row['id'],
            ]);
        }
        app_log('ingest.log', "row={$row['id']} tenant={$row['tenant_id']} attempt={$attempts} error={$error}");
    } finally {
        set_tenant_context(null);
    }
}

function ingest_endpoint_url(string $tenantSlug, array $source): string
{
    $url = rtrim((string) config('app_url'), '/') . '/ingest.php?t=' . $tenantSlug . '&s=' . $source['slug'];
    if ($source['auth_type'] === 'query') {
        $url .= '&k=TOKEN_ANDA';
    }
    return $url;
}
