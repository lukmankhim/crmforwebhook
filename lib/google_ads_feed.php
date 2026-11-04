<?php
declare(strict_types=1);

/**
 * Konversi offline ke Google Ads lewat feed CSV yang ditarik "Scheduled uploads" Google Ads (HTTPS, basic auth),
 * jadi tidak perlu developer token Google Ads API. Format: template "Conversions from clicks" dengan data
 * pengguna (enhanced conversions for leads): nomor HP dan email di-hash SHA-256, gclid diisi kalau ada.
 * Satu baris per pembayaran yang tidak dibatalkan, 7 hari terakhir; Order ID = nomor kuitansi supaya unggahan
 * harian yang tumpang tindih tidak menghitung dobel.
 */

const GADS_FEED_DAYS = 7;

function gads_feed_ready(): bool
{
    $s = tenant_settings();
    return $s['gads_conversion_name'] !== '' && $s['gads_feed_password'] !== '';
}

/** Nomor HP ke format E.164 (+62...) lalu SHA-256, sesuai aturan normalisasi Google. */
function gads_phone_hash(?string $phone): string
{
    $d = preg_replace('/\D/', '', (string) $phone);
    if ($d === '') {
        return '';
    }
    if (str_starts_with($d, '0')) {
        $d = '62' . substr($d, 1);
    } elseif (str_starts_with($d, '8')) {
        $d = '62' . $d;
    }
    return hash('sha256', '+' . $d);
}

function gads_email_hash(?string $email): string
{
    $e = strtolower(trim((string) $email));
    return $e === '' ? '' : hash('sha256', $e);
}

/** @return list<array<string, string>> baris feed untuk tenant aktif */
function gads_feed_rows(): array
{
    $name = tenant_setting('gads_conversion_name');
    $tz = new DateTimeZone('Asia/Jakarta');
    $rows = [];
    $sql = 'SELECT p.receipt_no, p.amount, p.created_at, l.phone, l.email, l.gclid
            FROM payments p JOIN leads l ON l.id = p.lead_id AND l.tenant_id = p.tenant_id
            WHERE p.tenant_id = :tid AND p.voided_at IS NULL AND p.created_at >= UTC_TIMESTAMP() - INTERVAL ' . GADS_FEED_DAYS . ' DAY
            ORDER BY p.id';
    foreach (q_tenant($sql) as $r) {
        $time = (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))->setTimezone($tz);
        $rows[] = [
            'Google Click ID' => (string) ($r['gclid'] ?? ''),
            'Email' => gads_email_hash($r['email']),
            'Phone Number' => gads_phone_hash($r['phone']),
            'Conversion Name' => $name,
            'Conversion Time' => $time->format('Y-m-d H:i:sO'),
            'Conversion Value' => number_format((float) $r['amount'], 2, '.', ''),
            'Conversion Currency' => 'IDR',
            'Order ID' => (string) $r['receipt_no'],
        ];
    }
    return $rows;
}

function gads_feed_csv(array $rows): string
{
    $cols = ['Google Click ID', 'Email', 'Phone Number', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency', 'Order ID'];
    $out = fopen('php://temp', 'w+');
    fputcsv($out, ['Parameters:TimeZone=Asia/Jakarta'], ',', '"', '');
    fputcsv($out, $cols, ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, array_map(fn($c) => $r[$c], $cols), ',', '"', '');
    }
    rewind($out);
    return (string) stream_get_contents($out);
}

/** GET /feeds/google-ads/<tenant>.csv, basic auth: user = slug tenant, password = gads_feed_password. */
function page_feed_google_ads(int|string $slug): never
{
    $slug = (string) $slug;
    $ip = client_ip();
    if (!rate_limit('feed-gads:' . $ip, 60, 60)) {
        json_response(['error' => 'rate_limited'], 429);
    }
    $tenant = q("SELECT id FROM tenants WHERE slug = ? AND status = 'active'", [$slug])->fetch();
    $authOk = false;
    if ($tenant) {
        set_tenant_context((int) $tenant['id']);
        [$user, $pass] = basic_auth_credentials();
        $expected = tenant_setting('gads_feed_password');
        $authOk = $expected !== '' && $user === $slug && hash_equals($expected, $pass);
    }
    if (!$authOk) {
        rate_limit('feed-bad:' . $ip, 30, 60);
        header('WWW-Authenticate: Basic realm="feed"');
        json_response(['error' => 'unauthorized'], 401);
    }
    if (tenant_setting('gads_conversion_name') === '') {
        json_response(['error' => 'conversion_name_missing'], 409);
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Cache-Control: no-store');
    echo gads_feed_csv(gads_feed_rows());
    exit;
}

/** @return array{0: string, 1: string} */
function basic_auth_credentials(): array
{
    if (isset($_SERVER['PHP_AUTH_USER'])) {
        return [(string) $_SERVER['PHP_AUTH_USER'], (string) ($_SERVER['PHP_AUTH_PW'] ?? '')];
    }
    // PHP-FPM di Apache: header Authorization diteruskan lewat .htaccess (E=HTTP_AUTHORIZATION).
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Basic\s+(\S+)$/i', $h, $m) && ($dec = base64_decode($m[1], true)) !== false && str_contains($dec, ':')) {
        return explode(':', $dec, 2);
    }
    return ['', ''];
}
