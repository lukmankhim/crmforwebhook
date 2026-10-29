<?php
// Salin ke config/config.php lalu: chmod 600 config/config.php
// File ini di luar docroot (public/). Jangan pernah taruh kredensial di public/.
return [
    // URL publik aplikasi, dipakai untuk menampilkan alamat webhook.
    'app_url' => 'https://crm.example.com',

    // 'production' memaksa cookie Secure. 'development' hanya untuk lokal.
    'env' => 'production',

    // Kunci enkripsi (secret TOTP, secret webhook). Buat dengan: php bin/keygen.php
    // Kalau kunci ini hilang, secret yang terenkripsi tidak bisa dibaca lagi. Simpan juga di luar VPS.
    'app_key' => '',

    // User aplikasi: hanya SELECT, INSERT, UPDATE, DELETE.
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=crm;charset=utf8mb4',
        'user' => 'crm_app',
        'pass' => '',
    ],

    // User migrasi: punya hak DDL. Hanya dipakai bin/migrate.php.
    'db_migrate' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=crm;charset=utf8mb4',
        'user' => 'crm_migrate',
        'pass' => '',
    ],

    // Notifikasi gagal (opsional). Kosongkan kalau belum dipakai.
    'telegram' => [
        'bot_token' => '',
        'chat_id'   => '',
    ],

    // Integrasi Google (opsional, Fase 3). Satu service account bisa dipakai banyak tenant:
    // tiap klien cukup menambahkan email service account di GA4 / Search Console mereka.
    // File JSON disimpan di luar folder aplikasi, chmod 600, pemilik www-data. Jangan pernah commit.
    'google' => [
        'credentials' => [
            // 'default' => '/etc/crm/google-service-account.json',
        ],
    ],

    // Folder log, session, lock. Default: storage/ di root repo (di luar docroot).
    'storage_path' => dirname(__DIR__) . '/storage',
];
