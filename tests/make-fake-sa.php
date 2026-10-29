<?php
// Buat service account PALSU untuk testing lokal (storage/test/, di-ignore git). Bukan kredensial Google sungguhan.
declare(strict_types=1);

$dir = __DIR__ . '/../storage/test';
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $privatePem);
file_put_contents($dir . '/fake-sa-public.pem', openssl_pkey_get_details($key)['key']);
file_put_contents($dir . '/fake-sa.json', json_encode([
    'type' => 'service_account',
    'project_id' => 'uji-lokal',
    'private_key_id' => 'palsu',
    'private_key' => $privatePem,
    'client_email' => 'crm-sync@uji-lokal.iam.gserviceaccount.com',
    'token_uri' => 'http://127.0.0.1:8090/token',
], JSON_PRETTY_PRINT));
chmod($dir . '/fake-sa.json', 0600);
echo realpath($dir . '/fake-sa.json') . "\n";
