<?php
declare(strict_types=1);

// Jalankan migrasi berurutan memakai user DB migrasi (punya hak DDL): php bin/migrate.php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';

$pdo = db_connect(config('db_migrate'));
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$done = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(ROOT . '/migrations/*.sql');
sort($files);

$applied = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (in_array($version, $done, true)) {
        continue;
    }
    echo "Menjalankan {$version}... ";
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    // DDL MySQL auto-commit, jadi tiap statement dijalankan satu per satu.
    foreach (preg_split('/;\s*$/m', $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
    $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
    echo "selesai\n";
    $applied++;
}
echo $applied ? "{$applied} migrasi dijalankan.\n" : "Tidak ada migrasi baru.\n";
