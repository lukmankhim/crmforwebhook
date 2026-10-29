<?php
declare(strict_types=1);

// Buat akun superadmin: php bin/create-superadmin.php <username> <nama> [email]
// Password diminta lewat prompt (tidak tersimpan di history shell). 2FA diatur saat login pertama.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';

[$script, $username, $name, $email] = $argv + [null, null, null, null];
if (!$username || !$name) {
    fwrite(STDERR, "Pakai: php bin/create-superadmin.php <username> <nama> [email]\n");
    exit(1);
}
$username = strtolower($username);
if (!preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
    fwrite(STDERR, "Username 3-64 karakter: huruf kecil, angka, titik, minus, garis bawah.\n");
    exit(1);
}
if (q('SELECT 1 FROM users WHERE username = ?', [$username])->fetchColumn()) {
    fwrite(STDERR, "Username sudah ada.\n");
    exit(1);
}

$password = getenv('CRM_SUPERADMIN_PASSWORD') ?: null;
if ($password === null) {
    echo 'Password (minimal 10 karakter): ';
    system('stty -echo');
    $password = trim((string) fgets(STDIN));
    system('stty echo');
    echo "\n";
}
if ($error = validate_new_password($password)) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}

q('INSERT INTO users (username, name, email, pass_hash, is_super) VALUES (?, ?, ?, ?, 1)', [$username, $name, $email ?: null, hash_password($password)]);
$id = (int) db()->lastInsertId();
audit('user_create', 'user', $id, ['username' => $username, 'super' => true], null, null);
echo "Superadmin {$username} dibuat. Login lalu atur 2FA.\n";
