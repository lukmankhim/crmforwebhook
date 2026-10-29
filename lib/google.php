<?php
declare(strict_types=1);

// Klien Google minimal: service account (JWT RS256) + panggilan JSON. Tanpa library eksternal.

const GOOGLE_SCOPES = 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly';

function google_endpoint(string $name): string
{
    $defaults = [
        'token' => 'https://oauth2.googleapis.com/token',
        'ga4' => 'https://analyticsdata.googleapis.com/v1beta',
        'gsc' => 'https://www.googleapis.com/webmasters/v3',
    ];
    // Override hanya untuk testing lokal dengan server tiruan.
    return rtrim((string) config('google.endpoints.' . $name, $defaults[$name]), '/');
}

/** Daftar kredensial yang dikonfigurasi di config.php: ref => path file JSON (di luar docroot, chmod 600). */
function google_credential_refs(): array
{
    $refs = config('google.credentials', []);
    return is_array($refs) ? $refs : [];
}

/** Baca file service account. Mengembalikan array atau melempar exception dengan pesan yang bisa dibaca admin. */
function google_service_account(string $ref): array
{
    $path = google_credential_refs()[$ref] ?? null;
    if ($path === null) {
        throw new RuntimeException("Kredensial \"$ref\" tidak ada di config.php (google.credentials).");
    }
    if (!is_readable($path)) {
        throw new RuntimeException("File kredensial \"$ref\" tidak bisa dibaca di server.");
    }
    $sa = json_decode((string) file_get_contents($path), true);
    if (!is_array($sa) || ($sa['type'] ?? '') !== 'service_account' || empty($sa['private_key']) || empty($sa['client_email'])) {
        throw new RuntimeException("File kredensial \"$ref\" bukan JSON service account yang valid.");
    }
    return $sa;
}

/** Email service account, untuk ditampilkan ke admin: email inilah yang ditambahkan klien di GA4/GSC. */
function google_client_email(string $ref): ?string
{
    try {
        return google_service_account($ref)['client_email'];
    } catch (RuntimeException) {
        return null;
    }
}

function base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Access token dari service account, di-cache di storage/cache sampai 5 menit sebelum kedaluwarsa. */
function google_access_token(string $ref): string
{
    $sa = google_service_account($ref);
    $cacheDir = storage_path('cache');
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0700, true);
    }
    $cacheFile = $cacheDir . '/google-token-' . sha1($ref . $sa['client_email']) . '.json';
    $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
    if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 300) {
        return $cached['token'];
    }

    $now = time();
    $header = base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = base64url(json_encode([
        'iss' => $sa['client_email'],
        'scope' => GOOGLE_SCOPES,
        'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $key = openssl_pkey_get_private($sa['private_key']);
    if ($key === false || !openssl_sign($header . '.' . $claims, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Private key service account tidak bisa dipakai untuk tanda tangan.');
    }
    $jwt = $header . '.' . $claims . '.' . base64url($signature);

    [$status, $body] = http_request('POST', google_endpoint('token'), ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]));
    if ($status !== 200 || empty($body['access_token'])) {
        throw new RuntimeException('Gagal mengambil token Google: ' . google_error_text($status, $body));
    }
    file_put_contents($cacheFile, json_encode(['token' => $body['access_token'], 'expires_at' => $now + (int) ($body['expires_in'] ?? 3600)]), LOCK_EX);
    chmod($cacheFile, 0600);
    return $body['access_token'];
}

function google_call(string $ref, string $method, string $url, ?array $payload = null): array
{
    $token = google_access_token($ref);
    $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
    [$status, $body] = http_request($method, $url, $headers, $payload === null ? null : json_encode($payload));
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException(google_error_text($status, $body));
    }
    return $body ?? [];
}

/** Pesan error Google yang dipendekkan, plus petunjuk untuk kasus yang paling sering. */
function google_error_text(int $status, ?array $body): string
{
    $msg = $body['error']['message'] ?? ($body['error_description'] ?? ($body['error'] ?? 'tanpa pesan'));
    $msg = is_string($msg) ? $msg : json_encode($msg);
    $hint = match ($status) {
        0 => ' (server tidak bisa menghubungi Google)',
        401 => ' (kredensial ditolak)',
        403 => ' (service account belum diberi akses ke properti ini)',
        404 => ' (ID properti atau URL situs tidak ditemukan)',
        429 => ' (kuota API habis, akan dicoba lagi)',
        default => '',
    };
    return mb_substr("HTTP $status: $msg$hint", 0, 480);
}

/** Request HTTP sederhana. Mengembalikan [status, body JSON atau null]. Status 0 kalau koneksi gagal. */
function http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) {
        $raw = json_encode(['error' => curl_error($ch)]);
    }
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    return [$status, is_array($decoded) ? $decoded : null];
}
