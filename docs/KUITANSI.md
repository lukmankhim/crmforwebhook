# Rencana fitur: kuitansi pembayaran

Status: fase 1 sampai 4 sudah dibangun dan diuji (`tests/e2e-kuitansi.js`). Versi lengkap (dengan contoh tenant) disimpan di luar repo.

Catatan implementasi: kunci Brevo, token webhook, dan token Meta disimpan per tenant di tabel `tenant_settings` (terenkripsi `app_key`), diisi admin lewat Pengaturan, Kuitansi & iklan. Setiap pembayaran dikirim sebagai satu event Purchase senilai pembayaran itu.

TumpuCRM bisa mencatat pembayaran DP dan pelunasan per lead, lalu mengirim kuitansi resmi ke email klien yang memintanya.

## PRD

**Masalah:** booking dari website tenant hanya dikirim ke email admin dan dicatat di file. Pembayaran dicatat di luar sistem, dan klien tidak menerima bukti bayar resmi.

**Pengguna:**

- Admin bisnis jasa (MC, event, vendor): mencatat pembayaran dan mengirim kuitansi.
- Klien bisnis itu: menerima kuitansi lewat email, membukanya di HP, atau mencetaknya.

**Fitur inti:**

1. Form booking tenant punya kolom email opsional dan centang "Kirim kuitansi ke email saya".
2. Setiap booking masuk ke CRM sebagai lead lewat endpoint ingest, lengkap dengan email dan pilihan kuitansi.
3. Di detail lead ada tombol Catat pembayaran: jenis (DP atau pelunasan), nominal, tanggal, metode, catatan.
4. Nomor kuitansi otomatis per tenant per bulan.
5. Halaman kuitansi siap cetak atau simpan PDF dari browser, dibuka lewat link bertoken.
6. Email HTML kuitansi (isi + link) hanya dikirim kalau klien opt-in dan emailnya ada.
7. Status email (terkirim, dibuka, mental, spam) tampil di CRM lewat webhook Brevo.

**Di luar cakupan:** pembayaran online (QRIS, payment gateway), invoice sebelum bayar, pajak dan e-meterai, pengingat pelunasan otomatis.

**Ukuran sukses:** semua pembayaran tercatat di CRM; kuitansi terkirim di bawah 1 menit; masuk kotak masuk utama Gmail uji; mencatat satu pembayaran di HP di bawah 1 menit.

## TRD

| Bagian | Pilihan | Alasan |
| --- | --- | --- |
| Aplikasi | PHP 8.3 native, server-rendered | Pola TumpuCRM, tanpa dependensi baru |
| Database | MariaDB, migrasi `003_payments.sql` | Jalur `bin/migrate.php` |
| Kirim email | API transaksional Brevo, kunci per tenant di config server | HTML + message-id untuk dicocokkan dengan webhook |
| Status email | Webhook Brevo ke endpoint CRM | delivered, opened, hard_bounce, spam, blocked |
| Kuitansi | HTML siap cetak (A4 dan HP) | Tanpa library PDF |
| Booking ke CRM | Website tenant kirim ke endpoint ingest yang sudah ada | Ingest + worker sudah teruji |

**Keamanan dan privasi:**

- Link kuitansi pakai token acak 32 byte, bukan ID berurutan. Halaman publik `noindex`, hanya data kuitansi itu.
- Webhook dicek pakai rahasia per tenant dan dibatasi `rate_limits`.
- Email klien hanya terlihat oleh user tenant itu (`q_tenant()`).
- Kuitansi tidak dikirim tanpa opt-in klien; waktu opt-in dicatat.
- Kunci API dan rahasia webhook hanya di `config/config.php` server.
- Nama usaha, logo, alamat, rekening tenant lewat config atau pengaturan tenant, tidak di kode.

**Performa:** email lewat antrian worker (cron per menit), tombol Simpan tidak menunggu Brevo.

## AFD

```
Klien isi form booking (email opsional + centang)
  -> website tenant: email ke admin (tetap) + kirim lead ke ingest CRM
  -> CRM: lead masuk (email + opt-in)
  -> admin: Catat pembayaran -> nomor kuitansi + token link
  -> opt-in dan email ada?
       ya    -> antrian worker -> Brevo kirim email -> klien buka kuitansi (/r/{token})
                Brevo webhook -> status email di CRM
       tidak -> kuitansi tetap ada di CRM, link bisa dibagikan manual
```

## DBD

Mood formal tapi hangat: putih, rapi, angka mudah dibaca, satu warna aksen dari `brand.accent` tenant (hanya garis atas, nomor kuitansi, label DP/LUNAS). Teks berwarna wajib lolos kontras AA; kalau aksen terlalu terang, pakai versi gelapnya untuk teks.

Urutan kuitansi: kepala (logo/nama usaha, kontak, nomor, tanggal), diterima dari, untuk pembayaran (jenis acara, tanggal, lokasi), rincian (jenis, metode, nominal besar, terbilang), ringkasan (nilai deal, total dibayar, sisa), kaki (catatan, penerima, kalimat penutup dari pengaturan tenant).

Satu kolom di HP 360 px tanpa geser samping, muat satu halaman A4 saat dicetak, tombol cetak disembunyikan saat print. Email memakai tabel HTML sederhana. Tanpa gradien, ilustrasi, ikon hiasan, stempel palsu.

## BSD

**`leads`:** tambah `email VARCHAR(190) NULL`, `receipt_optin_at DATETIME NULL`.

**`payments`:** `id`, `tenant_id`, `lead_id`, `kind ENUM('dp','pelunasan','lainnya')`, `amount DECIMAL(15,2)`, `paid_on DATE`, `method VARCHAR(60)`, `note VARCHAR(255) NULL`, `receipt_no VARCHAR(40)` (unik per tenant), `public_token CHAR(64)` (unik), `created_by`, `voided_at`, `void_reason`, `created_at` (UTC).

**`receipt_counters`:** `tenant_id`, `period` (YYYYMM), `last_no`; diambil di dalam transaksi.

**`email_messages`:** `id`, `tenant_id`, `payment_id`, `to_email`, `provider_msg_id`, `status` (queued, sent, delivered, opened, bounced, spam, failed), `last_event_at`, `error`, `created_at`.

| Endpoint | Akses | Fungsi |
| --- | --- | --- |
| `POST /leads/{id}/payments` | user tenant, CSRF | Catat pembayaran, nomor + token, antrian email bila opt-in |
| `POST /payments/{id}/void` | admin tenant | Batalkan kuitansi dengan alasan |
| `POST /payments/{id}/resend` | user tenant | Kirim ulang email |
| `GET /r/{token}` | publik | Halaman kuitansi |
| `POST /hooks/brevo/{tenant}` | Brevo, header `Authorization: Bearer` rahasia tenant | Event email, cocokkan `provider_msg_id` |
| `POST /ingest/...` (sudah ada) | token sumber | Lead booking + `email` + `receipt_optin` |

Payload booking: `name`, `phone`, `email`, `receipt_optin`, `product` (jenis acara), `city` (lokasi), `src_tag` = "booking"; tanggal dan konsep acara ke catatan aktivitas.

## IPD

**Fase 1, booking masuk CRM:** migrasi kolom lead; ingest terima `email` + `receipt_optin`; sumber ingest "booking"; form booking tenant tambah email + centang; uji satu booking.

**Fase 2, pembayaran dan kuitansi:** migrasi `payments`, `receipt_counters`, `email_messages`; form dan daftar pembayaran (batal, kirim ulang); nomor aman dari dobel; halaman `/r/{token}` + terbilang; email lewat worker; pengaturan tenant (nama, alamat, kontak, logo, kalimat kaki); uji DP dan pelunasan ke Gmail uji, cetak A4.

**Fase 3, status email:** endpoint webhook + rahasia per tenant; status per kuitansi; email mental menandai lead; uji event delivered dan opened.

**Fase 4, konversi offline ke iklan:** DP dan pelunasan dicatat manual, jadi dikirim balik ke platform iklan sebagai konversi offline.

| Kejadian di CRM | Meta (Conversions API) | Google Ads (konversi offline) |
| --- | --- | --- |
| Booking masuk | Lead (sudah dari website, tidak dikirim ulang) | Tidak dikirim |
| DP dicatat | Purchase, nilai DP, `action_source` = system_generated | Konversi DP dengan nilai DP |
| Pelunasan dicatat | Purchase kedua atau koreksi nilai ke nilai deal | Penyesuaian nilai ke nilai deal |
| Kuitansi dibatalkan | Tidak bisa ditarik, dicatat di CRM | Penarikan konversi |

Pencocokan: form booking mengirim `gclid`, `gbraid`, `wbraid`, `fbclid`, `_fbp`, `_fbc` (tambah kolom `fbp` dan `fbc` di `leads`), plus email dan nomor HP yang di-hash SHA-256 bila ada. Tabel `conversion_events`: `tenant_id`, `payment_id`, `platform`, `event_name`, `value`, `event_id` (dedup), `status`, `response`, `sent_at`; dikirim worker dengan coba ulang. Pengaturan tenant menyimpan ID dataset + token CAPI Meta dan akun + aksi konversi Google Ads. Uji lewat Test Events Meta dan laporan unggahan konversi Google.

Tenant yang belum punya dataset Meta tetap disiapkan: form booking push `booking_submit` ke dataLayer GTM dengan `event_id` unik (tag Pixel tinggal dipicu event ini), `event_id` yang sama ikut ke CRM untuk dedup Pixel dan CAPI, `_fbp`/`_fbc`/`fbclid` disimpan sejak awal, dan event dicatat di `conversion_events` dengan status menunggu pengaturan. Begitu dataset dan token diisi, event baru langsung terkirim; event lama hanya dikirim kalau masih dalam batas umur yang diterima Meta. Google Ads opsional per tenant.

**Sebelum fase selesai:** `tests/e2e.js` lolos, semua tombol diklik dan console bersih, tampilan HP tanpa geser samping dan tombol minimal 44 px, tidak ada nama tenant atau kunci di repo, backup DB sebelum migrasi.
