<?php
declare(strict_types=1);

// Jalankan tiap menit: * * * * * php /var/www/crm/cron/outbox-worker.php
// Mengirim email kuitansi (Brevo) dan konversi offline (Meta) dari antrian. Satu worker sekaligus (flock).
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';

$lockDir = storage_path('locks');
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0700, true);
}
$lock = fopen($lockDir . '/outbox-worker.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$deadline = time() + 50;
$sent = ['email' => 0, 'conversion' => 0];

// Lintas tenant: baris dipilih di sini, konteks tenant diset per baris.
while (time() < $deadline) {
    $rows = q("SELECT * FROM email_messages WHERE status = 'queued' AND (next_try_at IS NULL OR next_try_at <= UTC_TIMESTAMP())
               ORDER BY id LIMIT 20")->fetchAll();
    if (!$rows) {
        break;
    }
    foreach ($rows as $row) {
        set_tenant_context((int) $row['tenant_id']);
        try {
            receipt_email_send($row);
        } catch (Throwable $e) {
            app_log('outbox.log', "email={$row['id']} error " . $e->getMessage());
            q_tenant('UPDATE email_messages SET next_try_at = UTC_TIMESTAMP() + INTERVAL 15 MINUTE, error = :e WHERE tenant_id = :tid AND id = :id',
                ['e' => mb_substr($e->getMessage(), 0, 480), 'id' => $row['id']]);
        }
        set_tenant_context(null);
        $sent['email']++;
    }
}

// Event yang menunggu pengaturan: naik jadi pending begitu tenant mengisi dataset + token.
foreach (q("SELECT DISTINCT tenant_id FROM conversion_events WHERE status = 'waiting_config'")->fetchAll() as $t) {
    set_tenant_context((int) $t['tenant_id']);
    if (meta_capi_ready()) {
        q_tenant("UPDATE conversion_events SET status = 'pending', next_try_at = UTC_TIMESTAMP() WHERE tenant_id = :tid AND status = 'waiting_config'");
    }
    set_tenant_context(null);
}

while (time() < $deadline) {
    $rows = q("SELECT * FROM conversion_events WHERE status = 'pending' AND (next_try_at IS NULL OR next_try_at <= UTC_TIMESTAMP())
               ORDER BY id LIMIT 20")->fetchAll();
    if (!$rows) {
        break;
    }
    foreach ($rows as $row) {
        set_tenant_context((int) $row['tenant_id']);
        try {
            if (!meta_capi_ready()) {
                q_tenant("UPDATE conversion_events SET status = 'waiting_config' WHERE tenant_id = :tid AND id = :id", ['id' => $row['id']]);
            } else {
                conversion_send($row);
            }
        } catch (Throwable $e) {
            app_log('outbox.log', "conversion={$row['id']} error " . $e->getMessage());
            q_tenant('UPDATE conversion_events SET next_try_at = UTC_TIMESTAMP() + INTERVAL 15 MINUTE, response = :e WHERE tenant_id = :tid AND id = :id',
                ['e' => mb_substr($e->getMessage(), 0, 480), 'id' => $row['id']]);
        }
        set_tenant_context(null);
        $sent['conversion']++;
    }
}

if (in_array('-v', $argv, true)) {
    echo "email={$sent['email']} conversion={$sent['conversion']}\n";
}
