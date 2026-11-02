<?php
declare(strict_types=1);

// Konversi offline ke Meta Conversions API. Event selalu dicatat; kalau tenant belum mengisi dataset dan token,
// statusnya waiting_config dan baru dikirim worker setelah pengaturan diisi (selama masih dalam batas umur Meta).

const CONVERSION_MAX_AGE_DAYS = 7;
const CONVERSION_MAX_ATTEMPTS = 5;
const CONVERSION_LABELS = [
    'waiting_config' => 'Menunggu pengaturan Meta', 'pending' => 'Antri', 'sent' => 'Terkirim', 'failed' => 'Gagal',
    'expired' => 'Kedaluwarsa', 'cancelled' => 'Dibatalkan',
];

function conversion_insert(int $leadId, ?int $paymentId, string $eventName, string $eventId, ?int $value): void
{
    q_tenant('INSERT IGNORE INTO conversion_events (tenant_id, lead_id, payment_id, platform, event_name, event_id, event_time, value, status, next_try_at)
              VALUES (:tid, :l, :p, \'meta\', :n, :e, UTC_TIMESTAMP(), :v, :s, UTC_TIMESTAMP())', [
        'l' => $leadId, 'p' => $paymentId, 'n' => $eventName, 'e' => $eventId, 'v' => $value,
        's' => meta_capi_ready() ? 'pending' : 'waiting_config',
    ]);
}

/** Lead dari form website yang membawa event_id (sama dengan event Pixel di browser, untuk dedup). */
function conversion_queue_lead(int $leadId, string $eventId): void
{
    if (preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $eventId)) {
        conversion_insert($leadId, null, 'Lead', $eventId, null);
    }
}

function conversion_queue_purchase(array $lead, int $paymentId, int $amount, string $paidOn): void
{
    conversion_insert((int) $lead['id'], $paymentId, 'Purchase', 'pay-' . $paymentId, $amount);
}

function sha256_norm(?string $v): ?string
{
    $v = $v === null ? '' : strtolower(trim($v));
    return $v === '' ? null : hash('sha256', $v);
}

/** Bentuk satu event CAPI dari baris conversion_events + lead. */
function conversion_meta_event(array $ev, array $lead): array
{
    $userData = array_filter([
        'em' => ($h = sha256_norm($lead['email'])) ? [$h] : null,
        'ph' => ($h = sha256_norm(preg_replace('/\D/', '', (string) $lead['phone']))) ? [$h] : null,
        'external_id' => [hash('sha256', 'lead-' . $lead['id'])],
        'fbp' => $lead['fbp'] ?: null,
        'fbc' => $lead['fbc'] ?: meta_fbc_from_fbclid($lead),
        'client_user_agent' => $lead['user_agent'] ?: null,
    ]);
    $event = [
        'event_name' => $ev['event_name'],
        'event_time' => (new DateTimeImmutable($ev['event_time'], new DateTimeZone('UTC')))->getTimestamp(),
        'event_id' => $ev['event_id'],
        'action_source' => $ev['event_name'] === 'Lead' ? 'website' : 'system_generated',
        'user_data' => $userData,
    ];
    if ($ev['event_name'] === 'Lead' && !empty($lead['landing_url'])) {
        $event['event_source_url'] = $lead['landing_url'];
    }
    if ($ev['value'] !== null) {
        $event['custom_data'] = ['value' => (float) $ev['value'], 'currency' => $ev['currency']];
        if (!empty($lead['product'])) {
            $event['custom_data']['content_name'] = mb_substr((string) $lead['product'], 0, 100);
        }
    }
    return $event;
}

/** fbc dari fbclid kalau cookie _fbc tidak ikut: fb.1.<ms waktu klik>.<fbclid>. */
function meta_fbc_from_fbclid(array $lead): ?string
{
    if (empty($lead['fbclid'])) {
        return null;
    }
    $ms = (new DateTimeImmutable($lead['created_at'], new DateTimeZone('UTC')))->getTimestamp() * 1000;
    return 'fb.1.' . $ms . '.' . $lead['fbclid'];
}

/** Kirim satu event. Dipanggil worker dengan konteks tenant sudah diset. */
function conversion_send(array $ev): void
{
    $lead = find_lead((int) $ev['lead_id']);
    $age = time() - (new DateTimeImmutable($ev['event_time'], new DateTimeZone('UTC')))->getTimestamp();
    if (!$lead || $age > CONVERSION_MAX_AGE_DAYS * 86400) {
        q_tenant("UPDATE conversion_events SET status = 'expired', response = :r WHERE tenant_id = :tid AND id = :id", [
            'r' => $lead ? 'Lebih dari ' . CONVERSION_MAX_AGE_DAYS . ' hari, Meta tidak menerima event selama ini.' : 'Lead sudah tidak ada.',
            'id' => $ev['id'],
        ]);
        return;
    }
    $s = tenant_settings();
    $body = ['data' => [conversion_meta_event($ev, $lead)]];
    if ($s['meta_test_event_code'] !== '') {
        $body['test_event_code'] = $s['meta_test_event_code'];
    }
    $url = rtrim((string) config('meta.graph_url', 'https://graph.facebook.com/v21.0'), '/') . '/' . $s['meta_dataset_id'] . '/events';
    [$status, $resp] = http_post_json($url . '?access_token=' . rawurlencode($s['meta_access_token']), $body);
    $ok = $status === 200 && isset($resp['events_received']);
    $attempts = (int) $ev['attempts'] + 1;
    $summary = mb_substr($ok ? 'events_received=' . $resp['events_received'] : "HTTP {$status}: " . json_encode($resp['error']['message'] ?? $resp, JSON_UNESCAPED_UNICODE), 0, 480);
    if ($ok) {
        q_tenant("UPDATE conversion_events SET status = 'sent', attempts = :a, response = :r, sent_at = UTC_TIMESTAMP(), next_try_at = NULL
                  WHERE tenant_id = :tid AND id = :id", ['a' => $attempts, 'r' => $summary, 'id' => $ev['id']]);
        return;
    }
    $final = $attempts >= CONVERSION_MAX_ATTEMPTS || ($status >= 400 && $status < 500 && $status !== 429);
    q_tenant('UPDATE conversion_events SET status = :s, attempts = :a, response = :r,
              next_try_at = UTC_TIMESTAMP() + INTERVAL :d MINUTE WHERE tenant_id = :tid AND id = :id', [
        's' => $final ? 'failed' : 'pending', 'a' => $attempts, 'r' => $summary, 'd' => [1, 5, 15, 60, 240][$attempts - 1] ?? 240, 'id' => $ev['id'],
    ]);
    app_log('outbox.log', "conversion={$ev['id']} tenant={$ev['tenant_id']} attempt={$attempts} {$summary}");
}

function lead_conversions(int $leadId): array
{
    return q_tenant('SELECT * FROM conversion_events WHERE tenant_id = :tid AND lead_id = :l ORDER BY id DESC', ['l' => $leadId])->fetchAll();
}

/**
 * POST JSON pakai curl. Mengembalikan [status HTTP, body ter-decode atau ['raw' => ...]].
 * Kunci API dikirim lewat header atau query oleh pemanggil, tidak pernah dicatat ke log.
 */
function http_post_json(string $url, array $body, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return [0, ['error' => ['message' => 'Koneksi gagal: ' . $err]]];
    }
    $decoded = json_decode((string) $raw, true);
    return [$status, is_array($decoded) ? $decoded : ['raw' => mb_substr((string) $raw, 0, 300)]];
}
