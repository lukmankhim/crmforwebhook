<?php
// Server tiruan Google untuk testing lokal: php -S 127.0.0.1:8090 tests/mock-google.php
// Mengikuti bentuk respons resmi: OAuth token (JWT bearer), GA4 Data API runReport, Search Console API.
// Angka dibuat tetap supaya hasil sync bisa dicek: lihat tests/e2e.js bagian Fase 3.
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true) ?? [];
header('Content-Type: application/json');

function out(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function dates(string $from, string $to, int $skipLast = 0): array
{
    $out = [];
    $end = (new DateTimeImmutable($to))->modify("-$skipLast days");
    for ($d = new DateTimeImmutable($from); $d <= $end; $d = $d->modify('+1 day')) {
        $out[] = $d;
    }
    return $out;
}

if ($path === '/token') {
    parse_str((string) file_get_contents('php://input'), $form);
    $parts = explode('.', $form['assertion'] ?? '');
    $pub = openssl_pkey_get_public((string) file_get_contents(__DIR__ . '/../storage/test/fake-sa-public.pem'));
    $sig = base64_decode(strtr($parts[2] ?? '', '-_', '+/'));
    if (($form['grant_type'] ?? '') !== 'urn:ietf:params:oauth:grant-type:jwt-bearer' || count($parts) !== 3
        || openssl_verify($parts[0] . '.' . $parts[1], $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) {
        out(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.']);
    }
    out(200, ['access_token' => 'mock-token', 'expires_in' => 3599, 'token_type' => 'Bearer']);
}

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer mock-token') {
    out(401, ['error' => ['code' => 401, 'message' => 'Request had invalid authentication credentials.', 'status' => 'UNAUTHENTICATED']]);
}

if (preg_match('#^/ga4/properties/(\d+):runReport$#', $path, $m)) {
    if ($m[1] === '403403') {
        out(403, ['error' => ['code' => 403, 'message' => 'User does not have sufficient permissions for this property.', 'status' => 'PERMISSION_DENIED']]);
    }
    $range = $body['dateRanges'][0];
    if (empty($body['dimensions'])) {
        out(200, ['rows' => [['metricValues' => [['value' => '42']]]], 'rowCount' => 1]);
    }
    $rows = [];
    foreach (dates($range['startDate'], $range['endDate']) as $d) {
        $rows[] = ['dimensionValues' => [['value' => $d->format('Ymd')], ['value' => 'google'], ['value' => 'organic'], ['value' => '(organic)'], ['value' => '/home']],
                   'metricValues' => [['value' => '10'], ['value' => '8'], ['value' => '1']]];
        $rows[] = ['dimensionValues' => [['value' => $d->format('Ymd')], ['value' => 'facebook'], ['value' => 'cpc'], ['value' => 'promo'], ['value' => '/promo?utm_source=facebook']],
                   'metricValues' => [['value' => '5'], ['value' => '5'], ['value' => '2']]];
    }
    $limit = (int) ($body['limit'] ?? 10000);
    $offset = (int) ($body['offset'] ?? 0);
    out(200, ['rows' => array_slice($rows, $offset, $limit), 'rowCount' => count($rows)]);
}

if (preg_match('#^/gsc/sites/([^/]+)$#', $path, $m)) {
    $site = rawurldecode($m[1]);
    out(200, ['siteUrl' => $site, 'permissionLevel' => str_contains($site, 'noaccess') ? 'siteUnverifiedUser' : 'siteFullUser']);
}

if (preg_match('#^/gsc/sites/([^/]+)/searchAnalytics/query$#', $path)) {
    $rows = [];
    $withQuery = in_array('query', $body['dimensions'], true);
    foreach (dates($body['startDate'], $body['endDate'], 2) as $d) {
        $day = $d->format('Y-m-d');
        if ($withQuery) {
            $rows[] = ['keys' => [$day, 'https://contoh.com/home', 'kata a'], 'clicks' => 3, 'impressions' => 60, 'ctr' => 0.05, 'position' => 4];
            $rows[] = ['keys' => [$day, 'https://contoh.com/home', 'kata b'], 'clicks' => 1, 'impressions' => 30, 'ctr' => 0.033, 'position' => 7];
        } else {
            $rows[] = ['keys' => [$day, 'https://contoh.com/home'], 'clicks' => 4, 'impressions' => 100, 'ctr' => 0.04, 'position' => 5];
            $rows[] = ['keys' => [$day, 'https://contoh.com/promo'], 'clicks' => 1, 'impressions' => 50, 'ctr' => 0.02, 'position' => 12];
        }
    }
    $start = (int) ($body['startRow'] ?? 0);
    out(200, ['rows' => array_slice($rows, $start, (int) $body['rowLimit']), 'responseAggregationType' => 'byPage']);
}

out(404, ['error' => ['code' => 404, 'message' => 'Not found: ' . $path]]);
