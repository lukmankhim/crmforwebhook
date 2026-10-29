<?php
declare(strict_types=1);

// Jalankan tiap menit: * * * * * php /var/www/crm/cron/ingest-worker.php
// Memproses ingest_log berstatus received/retry menjadi lead. Satu worker sekaligus (flock).
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';

$lockDir = storage_path('locks');
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0700, true);
}
$lock = fopen($lockDir . '/ingest-worker.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // worker sebelumnya masih jalan
}

$deadline = time() + 50;
$processed = 0;
while (time() < $deadline) {
    // Lintas tenant: worker memilih baris, lalu konteks tenant diset per baris di ingest_process_row().
    $rows = q("SELECT * FROM ingest_log
               WHERE status IN ('received', 'retry') AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP())
               ORDER BY id LIMIT 50")->fetchAll();
    if (!$rows) {
        break;
    }
    foreach ($rows as $row) {
        ingest_process_row($row);
        $processed++;
    }
}

if ($processed > 0 && in_array('-v', $argv, true)) {
    echo "processed={$processed}\n";
}
