<?php
declare(strict_types=1);

// Jalankan tiap menit: * * * * * php /var/www/crm/cron/sync.php
// Hanya integrasi aktif yang sudah waktunya (tiap 60 menit) atau diminta manual dari dashboard.
// Tenant tanpa integrasi tidak disentuh sama sekali.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';

$lockDir = storage_path('locks');
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0700, true);
}
$lock = fopen($lockDir . '/sync.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

// Lintas tenant: memilih integrasi yang jatuh tempo, konteks tenant diset per baris di integration_sync().
$due = q("SELECT i.* FROM integrations i JOIN tenants t ON t.id = i.tenant_id
          WHERE i.status = 'active' AND t.status = 'active'
            AND (i.sync_requested_at IS NOT NULL OR i.last_sync_at IS NULL
                 OR i.last_sync_at < UTC_TIMESTAMP() - INTERVAL ? MINUTE)
          ORDER BY i.sync_requested_at IS NULL, i.last_sync_at
          LIMIT 20", [SYNC_INTERVAL_MINUTES])->fetchAll();

foreach ($due as $integration) {
    $result = integration_sync($integration);
    if (in_array('-v', $argv, true)) {
        echo "tenant={$integration['tenant_id']} provider={$integration['provider']} "
            . ($result['ok'] ? "rows={$result['rows']}" : "error={$result['error']}") . "\n";
    }
}
