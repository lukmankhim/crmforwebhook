<?php
declare(strict_types=1);

// Jalankan tiap jam: 17 * * * * php /var/www/crm/cron/cleanup.php
// Hapus payload mentah > 30 hari, counter rate limit kedaluwarsa, dan file session lama.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';

$payloads = q('UPDATE ingest_log SET payload = NULL WHERE payload IS NOT NULL AND created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY')->rowCount();
$limits = q('DELETE FROM rate_limits WHERE expires_at < ?', [time()])->rowCount();

$sessions = 0;
foreach (glob(storage_path('sessions/sess_*')) ?: [] as $file) {
    if (filemtime($file) < time() - SESSION_IDLE_SECONDS) {
        @unlink($file) && $sessions++;
    }
}

if (in_array('-v', $argv, true)) {
    echo "payload_dihapus={$payloads} rate_limit_dihapus={$limits} session_dihapus={$sessions}\n";
}
