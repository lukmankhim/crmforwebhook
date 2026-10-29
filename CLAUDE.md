# CLAUDE.md

Aplikasi CRM multi-tenant + lead funnel. Cara pasang di `docs/DEPLOY.md`.

Repo ini public: jangan tulis nama tenant, nama client, domain bisnis, atau data pribadi di kode, dokumen, contoh, maupun test. Pakai contoh netral (`bisnis-a`, `contoh.com`).

## Rencana fase

1. Fondasi: auth, tenant, user, pipeline, leads, audit, ingest API, backup.
2. Dashboard ringkas + daftar lead (kanban) + input manual + import CSV.
3. GA4 + Search Console sync dan halaman sumber.
4. Google Ads + Meta Ads sync (termasuk adapter Meta Lead Ads via Graph API).
5. Atribusi lead ke campaign, laporan client (branding per tenant), notifikasi.

## Status

- Fase 1 (fondasi) sudah dibangun: auth + 2FA, tenant, user, pipeline, lead (daftar, detail, input manual), audit log, endpoint webhook generik + worker, script backup.
- Fase 2 sudah dibangun: ringkasan di `/` (lead, closing, nilai, konversi, grafik harian, pipeline, follow up, per sumber), kanban di `/leads?view=kanban`, import CSV di `/leads/import` (khusus admin).
- Fase 3 sudah dibangun: framework integrasi per tenant (opsional) di `lib/integrations.php`, konektor GA4 dan Search Console, cron `cron/sync.php`, pengaturan di `/settings/integrations`, halaman `/sources`. Diuji dengan `tests/mock-google.php`, belum dengan akun Google sungguhan.
- Fase 4 dan 5 belum. Provider baru (Google Ads, Meta) ditambahkan di `integration_providers()`. Kerjakan satu fase per satu, tiap fase harus bisa dipakai.

## Aturan kode

- PHP 8.3 native tanpa framework, server-rendered, JS minimal (`public/assets/app.js`).
- Data bisnis wajib lewat `q_tenant()` (placeholder `:tid`, diisi dari konteks session/token). `q()` hanya untuk tabel global (users, tenants, tenant_users, rate_limits) dan worker.
- Waktu di DB selalu UTC. Tampilkan dengan `fmt_dt()` (zona waktu tenant).
- Semua POST lewat router dicek CSRF. Halaman baru didaftarkan di `$routes` di `public/index.php` lengkap dengan level akses.
- Aksi penting dicatat dengan `audit()`.
- Skema baru: tambah file `migrations/NNN_nama.sql`, jalankan `php bin/migrate.php`.
- Sebelum bilang selesai: jalankan `tests/e2e.js` (lihat `docs/DEPLOY.md`, bagian testing lokal) dan laporkan hasilnya.

# Aturan wajib untuk Claude Code

Aturan di bawah ini bukan saran.

## 1. Anti-slop (UI dan teks)

Ini filter, bukan gaya. Sebelum pakai sebuah teknik, harus bisa dijawab dalam satu baris: ini melayani apa? Kalau jawabannya cuma "biar kelihatan modern" atau "biar aman", tekniknya dibuang.

Hard gate, tidak boleh dilanggar sama sekali:

- Tanpa em dash (—) di semua teks yang kita tulis. Pakai koma, titik, titik dua, atau kurung.
- Mobile wajib rapi: tidak ada overflow horizontal, teks tidak keluar kotak, tombol minimal 44px, navbar nyaman.
- Tidak ada angka atau statistik tanpa sumber nyata. Kalau tidak ada data, jangan tampilkan angka.
- Tidak ada testimoni karangan, avatar AI, nama atau jabatan fiktif.
- Tidak ada klaim keamanan, ISO, SOC, atau performa tanpa bukti.
- Tidak ada logo, avatar, atau statistik yang dibuat tanpa konfirmasi. Pakai placeholder jujur `[LOGO]` atau `[REAL DATA]`.
- Tidak ada link navbar ke halaman yang belum ada.
- Kontras teks minimal WCAG AA: 4,5:1 untuk teks normal, 3:1 untuk teks besar.
- Tidak ada tombol atau form yang tidak melakukan apa pun.
- Setiap tampilan data wajib punya empty state, loading state, dan error state.
- FAQ hanya berisi pertanyaan nyata pengguna. Kalau tidak tahu, jangan bikin FAQ.
- Semua elemen interaktif bisa dipakai dengan keyboard, fokus terlihat jelas, modal bisa ditutup Escape.
- Jangan menambah fitur dengan skrip yang menimpa file CSS atau source. Tulis langsung di source.
- Kalau ada toggle tema, kedua mode harus jalan.
- Sebelum bilang selesai: jalankan aplikasinya, klik semua elemen satu per satu, cek console error, dan laporkan hasil klik itu sebagai bukti.
- Arah desain wajib ada. Tanpa arah, hasilnya wajib dilabeli "draft tanpa arah" dan tidak boleh dianggap final.

Larangan yang butuh alasan tertulis (boleh dipakai hanya kalau tujuannya jelas dan ditulis):

- Gradien biru-ungu, ungu-hitam, neon, glow latar sebagai default.
- Ikon sparkle, bintang, sihir, petir, robot, atau ikon Lucide seadanya.
- Font monospace besar untuk gaya terminal, label uppercase dengan tracking lebar.
- Latar grid, blueprint, titik-titik.
- Panah di hampir semua tombol sebagai hiasan.
- Badge kapsul "AI Powered", "Beta", "New", "Secure".
- Glassmorphism di lebih dari 1-2 elemen.
- Shadow besar di semua komponen.
- Kartu fitur yang semuanya identik ukuran, ikon, dan padding.
- Animasi fade up, float, scale, bounce dipakai semua sekaligus.
- Ilustrasi Undraw, Storyset, atau blob 3D generik.

Kualitas yang harus ada:

- Palet maksimal 2-3 warna inti plus 1 aksen.
- Layout mengikuti isi produk, bukan template Hero + 3 kartu + logo bar + 3 harga + footer 4 kolom.
- CTA spesifik, bukan "Get Started", "Learn More", "Explore".
- Tanpa buzzword: AI Powered, Seamless, Revolutionary, Cutting Edge, Ultimate, Powerful, Effortless.
- Tidak meniru tampilan Linear, Vercel, Stripe, atau Notion tanpa diminta.
- Setiap keputusan visual besar punya alasan satu baris.
- Tetapkan tiga dial sebelum membangun: ENERGY, RHYTHM, MOTION (1 kalem, 2 seimbang, 3 berani), dan konsisten sampai selesai.
- Wajib ada satu titik fokus per layar, satu aksen yang dipakai hemat, dan satu motif identitas yang khas.

Laporan gate sebelum serah terima: satu baris per item, PASS wajib disertai bukti konkret. Kalau ada satu FAIL, jangan serahkan hasilnya.

Untuk aplikasi CRM ini, arah desainnya: alat kerja internal dan client. Prioritas keterbacaan, kepadatan informasi yang rapi, dan kecepatan. Dial yang disarankan ENERGY 1 / RHYTHM 2 / MOTION 1. Bukan landing page marketing, jadi jangan pakai gaya hero besar, animasi scroll, atau dekorasi berlebihan.

## 2. Copywriting website

Dipakai untuk halaman publik, landing, laporan client, email, dan teks di dalam aplikasi.

- Jangan mengarang pelanggan, metrik, testimoni, penghargaan, sertifikasi, jaminan, tenggat, atau kelangkaan.
- Jangan pakai urgensi palsu, bukti sosial palsu, CTA yang bikin rasa bersalah, atau tekanan tersembunyi.
- Jangan sembunyikan risiko, harga, komitmen, pembatalan, atau syarat penting.
- Jangan pakai bahasa generik seperti "unlock your potential", "supercharge your workflow", "all-in-one platform".
- Tulis satu janji utama yang masuk akal, lalu dukung dengan bukti. Kalau bukti belum ada, tulis `[butuh bukti]` atau pakai kalimat yang jujur, bukan angka karangan.
- Sesuaikan panjang halaman dengan tingkat kesadaran pembaca: traffic panas butuh sedikit penjelasan, traffic dingin butuh lebih banyak bukti dan penanganan keberatan.
- CTA harus menjawab: apa yang terjadi setelah klik, nilai apa yang didapat, seberapa besar komitmennya, kenapa sekarang.
- Sebelum menulis, tentukan: produk, audiens, permukaan (hero, landing, pricing, CTA), tugas konversi, dan bukti yang tersedia. Kalau ada yang kurang dan mengubah hasil, tanya satu pertanyaan singkat.
- Saat mengkritik copy, sebut dulu masalah strategisnya (kesadaran tidak cocok, janji lemah, bukti kurang, mekanisme kabur, CTA tidak nyambung), baru tulis ulang.
- Uji akhir: apakah copy ini bertahan kalau dibaca calon pembeli yang pintar dan skeptis?

## 3. Konteks bahasa

- Semua teks aplikasi dan laporan client pakai bahasa Indonesia. Istilah teknis boleh tetap Inggris kalau lebih jelas.
- Nada bicara: langsung, spesifik, tanpa hiperbola.
- Nama tenant, logo, dan warna brand client tidak boleh dikarang. Ambil dari data tenant atau tanya.
