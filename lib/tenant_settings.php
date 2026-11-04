<?php
declare(strict_types=1);

/**
 * Pengaturan per tenant untuk kuitansi, email, dan konversi iklan. Nama usaha, logo, dan kunci tenant
 * disimpan di sini (bukan di kode). Nilai rahasia terenkripsi dengan app_key.
 *
 * [label, rahasia?, bawaan]
 */
const TENANT_SETTING_DEFS = [
    'receipt_business_name' => ['Nama usaha di kuitansi', false, ''],
    'receipt_address' => ['Alamat', false, ''],
    'receipt_contact' => ['Kontak (WA, email, situs)', false, ''],
    'receipt_footer' => ['Kalimat kaki kuitansi', false, 'Kuitansi ini sah tanpa tanda tangan basah.'],
    'receipt_prefix' => ['Awalan nomor kuitansi', false, 'KW'],
    'receipt_accent' => ['Warna aksen kuitansi (hex)', false, ''],
    'receipt_logo' => ['Logo kuitansi', false, ''],
    'mail_from_email' => ['Email pengirim', false, ''],
    'mail_from_name' => ['Nama pengirim', false, ''],
    'mail_reply_to' => ['Balasan ke (opsional)', false, ''],
    'brevo_api_key' => ['API key Brevo', true, ''],
    'brevo_webhook_token' => ['Token webhook Brevo', true, ''],
    'meta_dataset_id' => ['ID dataset Meta', false, ''],
    'meta_access_token' => ['Access token Conversions API', true, ''],
    'meta_test_event_code' => ['Test event code (opsional)', false, ''],
    'gads_conversion_name' => ['Nama konversi Google Ads', false, ''],
    'gads_feed_password' => ['Password feed Google Ads', true, ''],
    'gads_feed_sample' => ['Baris contoh feed Google Ads (setup)', false, ''],
];

/** Semua pengaturan tenant aktif. Rahasia didekripsi; pakai hanya di server, jangan tampilkan ulang di form. */
function tenant_settings(): array
{
    $cache = &$GLOBALS['TENANT_SETTINGS_CACHE'];
    $tid = tenant_id();
    if (isset($cache[$tid])) {
        return $cache[$tid];
    }
    $out = [];
    foreach (TENANT_SETTING_DEFS as $key => [, , $default]) {
        $out[$key] = $default;
    }
    foreach (q_tenant('SELECT setting_key, value, is_secret FROM tenant_settings WHERE tenant_id = :tid') as $row) {
        if (!array_key_exists($row['setting_key'], TENANT_SETTING_DEFS)) {
            continue;
        }
        $out[$row['setting_key']] = $row['is_secret'] ? (decrypt_secret($row['value']) ?? '') : (string) $row['value'];
    }
    return $cache[$tid] = $out;
}

function tenant_setting(string $key): string
{
    return tenant_settings()[$key] ?? '';
}

function tenant_setting_set(string $key, string $value): void
{
    if (!array_key_exists($key, TENANT_SETTING_DEFS)) {
        throw new LogicException('Pengaturan tidak dikenal: ' . $key);
    }
    $secret = TENANT_SETTING_DEFS[$key][1];
    $stored = $secret && $value !== '' ? encrypt_secret($value) : $value;
    q_tenant('INSERT INTO tenant_settings (tenant_id, setting_key, value, is_secret) VALUES (:tid, :k, :v, :s)
              ON DUPLICATE KEY UPDATE value = VALUES(value), is_secret = VALUES(is_secret)', [
        'k' => $key, 'v' => $stored, 's' => $secret ? 1 : 0,
    ]);
    unset($GLOBALS['TENANT_SETTINGS_CACHE'][tenant_id()]);
}

/** Logo hanya file yang ada di public/brand/ (diunggah ke server, tidak ikut git). */
function receipt_logo_path(): ?string
{
    $v = tenant_setting('receipt_logo');
    return preg_match('#^/brand/[A-Za-z0-9._-]+$#', $v) && is_file(ROOT . '/public' . $v) ? $v : null;
}

function receipt_email_ready(): bool
{
    $s = tenant_settings();
    return $s['brevo_api_key'] !== '' && filter_var($s['mail_from_email'], FILTER_VALIDATE_EMAIL) !== false;
}

function meta_capi_ready(): bool
{
    $s = tenant_settings();
    return preg_match('/^\d{5,20}$/', $s['meta_dataset_id']) === 1 && $s['meta_access_token'] !== '';
}
