<?php
declare(strict_types=1);

function client_ip(): string
{
    // Kalau di belakang Cloudflare/proxy, set real_ip di Nginx supaya REMOTE_ADDR sudah benar.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

/**
 * Fixed window counter di MySQL. Mengembalikan true kalau masih di bawah batas.
 */
function rate_limit(string $bucket, int $max, int $windowSeconds): bool
{
    $window = intdiv(time(), $windowSeconds);
    $key = substr($bucket, 0, 160) . ':' . $window;
    $expires = ($window + 1) * $windowSeconds;
    q('INSERT INTO rate_limits (k, hits, expires_at) VALUES (?, 1, ?)
       ON DUPLICATE KEY UPDATE hits = hits + 1', [$key, $expires]);
    $hits = (int) q('SELECT hits FROM rate_limits WHERE k = ?', [$key])->fetchColumn();
    return $hits <= $max;
}

function app_key(): string
{
    $key = base64_decode((string) config('app_key'), true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('app_key di config tidak valid. Buat dengan: php bin/keygen.php');
    }
    return $key;
}

function encrypt_secret(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, app_key()));
}

function decrypt_secret(?string $stored): ?string
{
    if ($stored === null || $stored === '') {
        return null;
    }
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, app_key());
    return $plain === false ? null : $plain;
}

function new_api_token(): string
{
    return 'crm_' . bin2hex(random_bytes(24));
}

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

function random_password(int $length = 14): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

function hash_password(string $password): string
{
    return defined('PASSWORD_ARGON2ID')
        ? password_hash($password, PASSWORD_ARGON2ID)
        : password_hash($password, PASSWORD_BCRYPT);
}
