# Pasang dan operasikan (Fase 1)

Target: VPS Ubuntu 24.04, Nginx, PHP-FPM 8.3, MySQL 8. Contoh path: `/var/www/crm`.

## 1. Paket

```bash
sudo apt update
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-curl fail2ban rclone certbot python3-certbot-nginx
```

## 2. Kode dan folder

```bash
sudo git clone https://github.com/lukmankhim/crmforwebhook /var/www/crm
sudo chown -R root:root /var/www/crm
sudo chown -R www-data:www-data /var/www/crm/storage
sudo chmod -R 700 /var/www/crm/storage
```

## 3. Database

Edit password di `deploy/mysql-users.sql`, lalu:

```bash
sudo mysql < /var/www/crm/deploy/mysql-users.sql
```

Hasilnya tiga user: `crm_app` (tanpa DDL, dipakai aplikasi), `crm_migrate` (DDL, hanya untuk migrasi), `crm_backup` (hanya baca, untuk dump).

## 4. Config

```bash
cd /var/www/crm
sudo cp config/config.example.php config/config.php
php bin/keygen.php            # salin hasilnya ke app_key
sudo nano config/config.php   # isi app_url, app_key, password DB, Telegram (opsional)
sudo chown www-data:www-data config/config.php
sudo chmod 600 config/config.php
```

Simpan salinan `app_key` di luar VPS (password manager). Tanpa kunci ini, secret 2FA dan secret webhook tidak bisa dibaca.

## 5. Migrasi dan superadmin

```bash
sudo -u www-data php bin/migrate.php
sudo -u www-data php bin/create-superadmin.php admin "Nama Anda" email@contoh.com
```

Login pertama superadmin wajib mengatur 2FA (Google Authenticator, Authy, atau sejenisnya).

## 6. Nginx + HTTPS

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/crm   # ganti domain dulu
sudo ln -s /etc/nginx/sites-available/crm /etc/nginx/sites-enabled/
sudo certbot --nginx -d crm.example.com
sudo nginx -t
sudo systemctl reload nginx
```

## 7. Cron

```bash
sudo crontab -u www-data /var/www/crm/deploy/crontab
```

- `ingest-worker.php` tiap menit: mengubah webhook yang masuk jadi lead, coba ulang dengan jeda 1, 5, 15, 60 menit, gagal ke-5 kirim Telegram.
- `sync.php` tiap menit: hanya menjalankan integrasi yang aktif dan sudah 60 menit sejak sync terakhir (atau diminta lewat tombol Sync sekarang).
- `cleanup.php` tiap jam: hapus payload mentah lebih dari 30 hari, counter rate limit, dan session lama.

## 8. fail2ban

```bash
sudo cp deploy/fail2ban/filter.d/crm-auth.conf /etc/fail2ban/filter.d/
sudo cp deploy/fail2ban/jail.d/crm-auth.local /etc/fail2ban/jail.d/
sudo systemctl restart fail2ban
sudo fail2ban-client status crm-auth
```

## 9. Backup harian ke luar VPS

1. Buat `/root/.crm-backup.cnf` (chmod 600) dengan user `crm_backup`.
2. `rclone config` untuk membuat remote, misalnya `backup` ke Backblaze B2 atau Google Drive.
3. Jalankan sekali manual: `sudo REMOTE=backup:crm /var/www/crm/deploy/backup.sh`
4. Pasang cron root: `23 2 * * * /var/www/crm/deploy/backup.sh >> /var/log/crm-backup.log 2>&1`

### Tes restore (wajib pernah dilakukan)

```bash
rclone copy backup:crm/db-YYYYMMDD-HHMMSS.sql.gz /tmp/
sudo mysql -e "CREATE DATABASE crm_restore_test"
gunzip -c /tmp/db-YYYYMMDD-HHMMSS.sql.gz | sudo mysql crm_restore_test
sudo mysql crm_restore_test -e "SELECT COUNT(*) FROM leads; SELECT COUNT(*) FROM users;"
sudo mysql -e "DROP DATABASE crm_restore_test"
```

Catat tanggal tes restore terakhir.

## Integrasi Google (opsional, bisa kapan saja)

GA4 dan Search Console memakai **satu service account** untuk semua tenant. Klien tidak memasang apa pun, cukup memberi akses ke email service account. Tenant yang tidak menyambungkan integrasi tetap jalan normal.

1. Di Google Cloud Console: buat project, aktifkan **Google Analytics Data API** dan **Google Search Console API**.
2. IAM, Service Accounts, buat service account (tanpa role project), lalu Keys, Add key, JSON. File JSON terunduh.
3. Upload file itu ke server di luar folder aplikasi, lalu kunci aksesnya:

```bash
sudo mkdir -p /etc/crm
sudo mv google-service-account.json /etc/crm/
sudo chown www-data:www-data /etc/crm/google-service-account.json
sudo chmod 600 /etc/crm/google-service-account.json
```

4. Daftarkan di `config/config.php`:

```php
'google' => [
    'credentials' => [
        'default' => '/etc/crm/google-service-account.json',
    ],
],
```

5. Per tenant: Pengaturan, Integrasi. Halaman itu menampilkan email service account. Klien menambahkan email itu sebagai Viewer di GA4 (Admin, Property access management) dan sebagai user di Search Console. Lalu isi Property ID / properti Search Console, simpan, dan klik Tes koneksi.
6. Cron `sync.php` menarik 90 hari ke belakang saat pertama kali, lalu tiap 60 menit menarik beberapa hari terakhir. Gagal 2x berturut-turut mengirim notifikasi Telegram.

File JSON jangan pernah di-commit, dikirim lewat chat, atau ditaruh di `public/`.

## Nama dan logo aplikasi (opsional)

Bawaannya TumpuCRM (logo dan favicon di `public/assets/brand/`, aksen `#357a4e`), tanpa perlu config apa pun. Kalau satu server mau memakai nama dan logo lain, ambil dari situs bisnisnya:

```bash
cd /var/www/crm
sudo -u www-data php bin/fetch-brand.php https://situs-anda.com --name="Nama CRM"
```

Script menyimpan file ke `public/brand/` (tidak ikut git), menolak file yang bukan gambar, lebih dari 1MB, atau SVG yang berisi script. Lalu ia mencetak potongan `'brand' => [...]` untuk ditempel ke `config/config.php`. Kalau logo tidak ketemu otomatis, taruh file logo manual di `public/brand/` dan isi path-nya di config.

Warna aksen (tombol, link, grafik) bisa ikut warna brand lewat `'accent' => '#123456'` di bagian `brand`. Ambil dari warna situs bisnis, jangan menebak. Aplikasi mengecek kontras: teks putih di atas aksen dan aksen di atas latar terang harus minimal 4,5:1. Kalau kurang, warna digelapkan otomatis dan dicatat di `storage/logs/app.log`. Hex yang tidak valid diabaikan (pakai warna bawaan).

## Pakai pertama kali

1. Login superadmin, buat tenant (misalnya `bisnis-a`, `client-b`) di menu Tenant.
2. Di tenant itu: Pengaturan, Pengguna, tambah admin dan sales. Password sementara tampil sekali.
3. Pengaturan, Webhook & API: buat token, buat sumber webhook, cek mapping dengan contoh payload.
4. Pasang URL webhook di landing page atau gateway WA.

## Contoh kirim lead

```bash
curl -X POST "https://crm.example.com/ingest.php?t=bisnis-a&s=landing-page" \
  -H "Authorization: Bearer crm_xxx" \
  -H "Content-Type: application/json" \
  -d '{"id":"form-123","phone":"0812 3456 7890","name":"Budi","utm_campaign":"promo","gclid":"Cj0..."}'
```

Respons:

| Kode | Arti |
|---|---|
| 200 `received` | Tersimpan, diproses worker dalam 1 menit |
| 200 `duplicate` | external_id yang sama sudah pernah masuk |
| 401 | Token, signature, atau google_key salah |
| 404 | Tenant atau sumber tidak dikenal / nonaktif |
| 413 | Body lebih dari 64KB |
| 422 | Nomor HP tidak ada atau tidak valid (tetap tercatat di log untuk perbaikan mapping) |
| 429 | Melewati batas 120 request per menit per token |

Dengan signature HMAC: header `X-Signature: sha256=<hex hmac-sha256 dari body mentah dengan secret sumber>`.

## Testing lokal

Butuh PHP 8.3, MySQL 8, Node + Playwright.

```bash
cp config/config.example.php config/config.php   # isi DB lokal, env=development, app_url=http://127.0.0.1:8080
CRM_ALLOW_RESET=yes SUPER_PASS='password-uji-123' tests/reset-local.sh
php -S 127.0.0.1:8080 -t public bin/dev-router.php
```

Untuk tes integrasi Google tanpa akun Google sungguhan, pakai server tiruan dan service account palsu:

```bash
php tests/make-fake-sa.php
php -S 127.0.0.1:8090 tests/mock-google.php
```

Lalu tambahkan di `config/config.php` lokal (path dari output `make-fake-sa.php`):

```php
'google' => [
    'credentials' => ['default' => '/path/ke/storage/test/fake-sa.json', 'rusak' => '/tidak/ada.json'],
    'endpoints' => ['token' => 'http://127.0.0.1:8090/token', 'ga4' => 'http://127.0.0.1:8090/ga4', 'gsc' => 'http://127.0.0.1:8090/gsc'],
],
```

Di terminal lain:

```bash
SUPER_PASS='password-uji-123' node tests/e2e.js
```

`tests/reset-local.sh` menghapus seluruh database lokal. Jangan dijalankan di server produksi.
