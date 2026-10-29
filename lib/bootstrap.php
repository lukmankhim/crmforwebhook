<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

$cfgFile = getenv('CRM_CONFIG') ?: ROOT . '/config/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit("Config belum ada. Salin config/config.example.php ke config/config.php.\n");
}
$GLOBALS['CONFIG'] = require $cfgFile;

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function storage_path(string $sub = ''): string
{
    $base = rtrim((string) config('storage_path', ROOT . '/storage'), '/');
    return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
}

function is_production(): bool
{
    return config('env', 'production') === 'production';
}

function app_log(string $file, string $message): void
{
    $line = gmdate('Y-m-d H:i:s') . ' ' . $message . "\n";
    @file_put_contents(storage_path('logs/' . $file), $line, FILE_APPEND | LOCK_EX);
}

require __DIR__ . '/db.php';
require __DIR__ . '/security.php';
require __DIR__ . '/tenancy.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/totp.php';
require __DIR__ . '/audit.php';
require __DIR__ . '/phone.php';
require __DIR__ . '/view.php';
require __DIR__ . '/leads.php';
require __DIR__ . '/ingest.php';
require __DIR__ . '/notify.php';
