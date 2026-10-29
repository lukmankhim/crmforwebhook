// Uji end-to-end Fase 1 dengan Playwright terhadap server lokal.
// Pakai: BASE=http://127.0.0.1:8080 SUPER_USER=admin SUPER_PASS=... node tests/e2e.js
// Butuh DB bersih yang hanya berisi superadmin (lihat docs/DEPLOY.md bagian testing).
const { chromium } = require('playwright');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const SUPER_USER = process.env.SUPER_USER || 'admin';
const SUPER_PASS = process.env.SUPER_PASS;
const ROOT = path.resolve(__dirname, '..');

function base32Decode(s) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of s.replace(/[^A-Z2-7]/gi, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}
function totp(secret, offset = 0) {
  const step = Math.floor(Date.now() / 30000) + offset;
  const buf = Buffer.alloc(8);
  buf.writeBigUInt64BE(BigInt(step));
  const h = crypto.createHmac('sha1', base32Decode(secret)).update(buf).digest();
  const o = h[19] & 0xf;
  const v = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];
  return String(v % 1000000).padStart(6, '0');
}
const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  (' + detail + ')' : ''}`);
}
function runWorker() {
  return execFileSync('php', [path.join(ROOT, 'cron/ingest-worker.php'), '-v'], { encoding: 'utf8' }).trim();
}
async function noOverflow(page, label) {
  const o = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check(`mobile tanpa overflow horizontal: ${label}`, o <= 0, `selisih ${o}px`);
}
async function waitNextStep() {
  // Kode TOTP tidak boleh dipakai ulang di step yang sama.
  const ms = 30000 - (Date.now() % 30000) + 500;
  await new Promise((r) => setTimeout(r, ms));
}

(async () => {
  if (!SUPER_PASS) throw new Error('SUPER_PASS wajib diisi');
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const consoleErrors = [];
  const expectedErrorPages = new Set(); // halaman yang memang diuji harus 403/404
  page.on('console', (m) => {
    if (m.type() === 'error' && !expectedErrorPages.has(m.location().url)) consoleErrors.push(m.text() + ' @ ' + m.location().url);
  });
  page.on('pageerror', (e) => consoleErrors.push(e.message));
  page.on('dialog', (d) => d.accept());

  // 1. Login gagal lalu login superadmin + setup 2FA wajib
  await page.goto(BASE + '/');
  check('belum login diarahkan ke /login', page.url().endsWith('/login'));
  await page.fill('#login', SUPER_USER);
  await page.fill('#password', 'salah-password');
  await page.click('button[type=submit]');
  check('password salah ditolak', (await page.textContent('.flash--error')).includes('salah'));
  await page.fill('#login', SUPER_USER);
  await page.fill('#password', SUPER_PASS);
  await page.click('button[type=submit]');
  check('superadmin tanpa 2FA dipaksa setup 2FA', page.url().endsWith('/login/2fa-setup'));
  const secret = (await page.getAttribute('[data-copy]', 'data-copy')).trim();
  await page.fill('#code', '000000');
  await page.click('form[action="/login/2fa-setup"] button');
  check('kode 2FA salah ditolak', (await page.textContent('.flash--error')).includes('tidak cocok'));
  await page.fill('#code', totp(secret));
  await page.click('form[action="/login/2fa-setup"] button');
  check('setup 2FA berhasil, masuk ke /tenants (belum ada tenant)', page.url().endsWith('/tenants'), page.url());

  // 2. Buat tenant
  await page.fill('#t-name', 'Tenant Uji');
  await page.fill('#t-slug', 'tenant-uji');
  await page.click('form[action="/tenants"] button[type=submit]');
  check('tenant dibuat dan aktif', (await page.textContent('.brand__tenant')).includes('Tenant Uji'));

  // 3. Buat user sales
  await page.goto(BASE + '/settings/users');
  await page.fill('#u-name', 'Sales Satu');
  await page.fill('#u-username', 'sales1');
  await page.selectOption('#u-role', 'sales');
  await page.click('form[action="/settings/users"] button[type=submit]');
  const salesPass = (await page.getAttribute('.reveal [data-copy]', 'data-copy')).trim();
  check('user sales dibuat, password sementara tampil sekali', salesPass.length >= 12);
  await page.reload();
  check('password sementara tidak tampil lagi setelah reload', (await page.$('.reveal')) === null);

  // 4. Token + sumber webhook
  await page.goto(BASE + '/settings/ingest');
  await page.fill('#key-name', 'Landing page');
  await page.click('form[action="/settings/ingest/keys"] button');
  const token = (await page.getAttribute('.reveal [data-copy]', 'data-copy')).trim();
  check('token API dibuat', token.startsWith('crm_'));
  await page.fill('#src-label', 'Landing page');
  await page.fill('#src-slug', 'landing-page');
  await page.selectOption('#src-adapter', 'generic');
  await page.click('form[action="/settings/ingest/sources"] button');
  check('sumber webhook dibuat', page.url().includes('/settings/ingest/sources/'));
  const sourceUrl = page.url();

  // Uji mapping (dry run)
  await page.fill('#sample', JSON.stringify({ id: 'x1', phone: '0812 3456 7890', name: 'Budi' }));
  await page.click('form[action$="/test"] button');
  check('uji mapping membaca nomor jadi 62', (await page.textContent('.test-result')).includes('6281234567890'));
  await page.fill('#sample', JSON.stringify({ id: 'x1', telp: '0812' }));
  await page.click('form[action$="/test"] button');
  check('uji mapping memberi error kalau nomor tidak ada', (await page.textContent('.test-result .flash--error')).includes('phone_path'));

  // 5. Kirim webhook
  const send = (body, headers = {}) => fetch(BASE + '/ingest.php?t=tenant-uji&s=landing-page', {
    method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token, ...headers }, body: JSON.stringify(body),
  });
  let r = await send({ id: 'lp-1', phone: '+62 812-1111-2222', name: 'Andi', gclid: 'Cj0KCQ-abc_123', utm_campaign: 'promo-okt', landing_url: 'https://contoh.com/?gclid=Cj0KCQ-abc_123', stage: 'closing', deal_value: 999999 });
  check('webhook valid diterima 200', r.status === 200, String(r.status));
  r = await send({ id: 'lp-1', phone: '+62 812-1111-2222' });
  check('webhook dengan external_id sama dianggap duplikat', (await r.json()).status === 'duplicate');
  r = await send({ id: 'lp-2', phone: '0812-1111-2222', fbclid: 'fb.1.abc' });
  check('nomor sama, external_id beda diterima', r.status === 200);
  r = await send({ id: 'lp-3', phone: '12345' });
  check('nomor tidak valid ditolak 422', r.status === 422, String(r.status));
  r = await send({ id: 'lp-4', phone: '08121111333' }, { Authorization: 'Bearer salah' });
  check('token salah ditolak 401', r.status === 401, String(r.status));
  r = await fetch(BASE + '/ingest.php?t=tidak-ada&s=landing-page', { method: 'POST', headers: { Authorization: 'Bearer ' + token }, body: '{}' });
  check('tenant tidak dikenal ditolak 404', r.status === 404, String(r.status));
  r = await fetch(BASE + '/ingest.php?t=tenant-uji&s=landing-page', { method: 'POST', headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' }, body: JSON.stringify({ phone: '081211113333', pad: 'x'.repeat(70000) }) });
  check('body lebih dari 64KB ditolak 413', r.status === 413, String(r.status));

  const out = runWorker();
  check('worker memproses antrean', out.includes('processed=2'), out);

  // 6. Lihat lead
  await page.goto(BASE + '/leads');
  const rows = await page.$$('.table tbody tr');
  check('hanya 1 lead untuk nomor yang sama', rows.length === 1, `${rows.length} baris`);
  const listText = await page.textContent('.table');
  check('nomor disamarkan di daftar', /62812\*+222/.test(listText) && !listText.includes('6281211112222'));
  await page.click('.rowlink');
  const detail = await page.textContent('main');
  check('nomor penuh tampil di detail', detail.includes('6281211112222'));
  check('stage dari luar diabaikan, lead masuk sebagai Baru', (await page.textContent('.page-head .stage__label')).trim() === 'Baru');
  check('gclid disimpan apa adanya', detail.includes('Cj0KCQ-abc_123'));
  check('fbclid dari request kedua mengisi atribusi kosong', detail.includes('fb.1.abc'));
  check('nominal dari luar diabaikan', !(await page.inputValue('#deal_value')));
  const leadUrl = page.url();

  // 7. Stage, edit, catatan
  await page.selectOption('#stage', 'lost');
  check('alasan lost muncul saat pilih stage lost', await page.isVisible('#lost_reason_stage'));
  await page.selectOption('#stage', 'qualified');
  check('alasan lost tersembunyi untuk stage lain', !(await page.isVisible('#lost_reason_stage')));
  await page.click('form[data-stage-form] button');
  check('pindah stage ke Qualified', (await page.textContent('.page-head .stage__label')).trim() === 'Qualified');
  await page.fill('#product', 'Mesin press');
  await page.fill('#quote_value', '15.000.000');
  await page.click('form[action$="' + new URL(leadUrl).pathname + '"] button[type=submit]');
  check('edit data lead tersimpan', (await page.inputValue('#quote_value')) === '15000000');
  await page.fill('#note', 'Minta katalog via WA');
  await page.click('form[action$="/note"] button');
  check('catatan muncul di aktivitas', (await page.textContent('.timeline')).includes('Minta katalog via WA'));

  // 8. Input manual + duplikat
  await page.goto(BASE + '/leads/new');
  await page.fill('#phone', '0813 9999 0000');
  await page.fill('#name', 'Citra');
  await page.click('form[action="/leads"] button');
  check('lead manual dibuat', page.url().match(/\/leads\/\d+$/) !== null);
  await page.goto(BASE + '/leads/new');
  await page.fill('#phone', '6281399990000');
  await page.click('form[action="/leads"] button');
  check('lead manual duplikat diarahkan ke lead lama', (await page.textContent('.flash--error')).includes('sudah ada'));

  // 9. Filter
  await page.goto(BASE + '/leads?stage=qualified');
  check('filter stage bekerja', (await page.$$('.table tbody tr')).length === 1);
  await page.goto(BASE + '/leads?q=tidakada');
  check('empty state saat filter tanpa hasil', (await page.textContent('.empty')).includes('Tidak ada lead'));

  // 9b. Fase 2: kanban
  await page.goto(BASE + '/leads?view=kanban');
  check('kanban menampilkan kolom per stage', (await page.$$('.kanban__col')).length === 6);
  const citraCard = page.locator('.kcard', { hasText: 'Citra' });
  await citraCard.locator('select').selectOption('dihubungi');
  await citraCard.locator('button').click();
  check('pindah kartu kanban tetap di kanban', page.url().includes('view=kanban'), page.url());
  check('kartu pindah ke kolom Dihubungi', await page.locator('#col-dihubungi').locator('xpath=..').locator('.kcard', { hasText: 'Citra' }).count() === 1);
  check('kolom lost tidak punya tombol pindah', await page.locator('.kanban__col--lost form').count() === 0);

  // 9c. Fase 2: import CSV
  const d = (n) => { const t = new Date(Date.now() - n * 86400000); return t; };
  const dmy = (t) => `${String(t.getDate()).padStart(2, '0')}/${String(t.getMonth() + 1).padStart(2, '0')}/${t.getFullYear()}`;
  const ymd = (t) => `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
  const csv = [
    'Nama;No HP;Status;Tanggal;Nilai deal',
    `Gita;0812 5555 0001;Closing;${dmy(d(14))};12.500.000`,
    'Hadi;6,28126E+12;Baru;;',
    `Ika;081255550002;tidak ada;${ymd(d(9))};`,
    'Gita lagi;0812-5555-0001;;;',
    'Andi lama;6281211112222;;;',
  ].join('\r\n');
  await page.goto(BASE + '/leads');
  await page.click('a[href="/leads/import"]');
  await page.setInputFiles('#file', { name: 'leads-lama.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
  await page.click('form[action="/leads/import"] button');
  check('import: halaman pasangkan kolom terbuka', page.url().endsWith('/leads/import/map'));
  check('import: pemisah titik koma dikenali (5 kolom)', (await page.$$('select[name^="col["]')).length === 5);
  check('import: kolom nomor ditebak otomatis', (await page.inputValue('#col-1')) === 'phone');
  check('import: kolom nama, stage, tanggal, nilai ditebak', (await page.inputValue('#col-0')) === 'name' && (await page.inputValue('#col-2')) === 'stage' && (await page.inputValue('#col-3')) === 'created_at' && (await page.inputValue('#col-4')) === 'deal_value');
  await page.selectOption('#col-0', 'phone');
  await page.click('form[action="/leads/import/run"] button');
  check('import: field ganda ditolak', (await page.textContent('.flash--error')).includes('lebih dari satu kolom'));
  await page.selectOption('#col-0', 'name');
  await page.click('form[action="/leads/import/run"] button');
  const res = await page.textContent('main');
  const num = (label) => { const m = res.match(new RegExp(label + '\\s*(\\d+)')); return m ? Number(m[1]) : -1; };
  check('import: 2 lead baru tersimpan', num('Lead baru tersimpan') === 2, String(num('Lead baru tersimpan')));
  check('import: nomor yang sudah ada dilewati', num('Dilewati, nomor sudah ada') === 1);
  check('import: nomor dobel di file dilewati', num('Dilewati, nomor dobel di file') === 1);
  check('import: nomor tidak valid dilewati dengan nomor baris', num('Dilewati, nomor tidak valid') === 1 && res.includes('Baris dengan nomor tidak valid: 3'));
  check('import: petunjuk format 6,28E+12 tampil', res.includes('6,28E+12'));
  check('import: stage tidak dikenal dilaporkan', num('Stage tidak dikenal \\(masuk stage awal\\)') === 1);
  await page.goto(BASE + '/leads?source=import');
  check('import: lead hasil import bersumber Import', (await page.$$('.table tbody tr')).length === 2);
  await page.click('.rowlink:text-is("Gita")');
  check('import: stage Closing dan nilai deal terbawa', (await page.textContent('.page-head .stage__label')).trim() === 'Closing' && (await page.inputValue('#deal_value')) === '12500000');
  await page.goto(BASE + '/leads/import');
  await page.setInputFiles('#file', { name: 'batal.csv', mimeType: 'text/csv', buffer: Buffer.from('hp\n081200000000') });
  await page.click('form[action="/leads/import"] button');
  await page.click('form[action="/leads/import/cancel"] button');
  check('import: batal menghapus file dan kembali ke upload', page.url().endsWith('/leads/import') && (await page.textContent('.flash--info')).includes('dibatalkan'));
  await page.setInputFiles('#file', { name: 'data.xlsx', mimeType: 'application/octet-stream', buffer: Buffer.from('xx') });
  await page.click('form[action="/leads/import"] button');
  check('import: file selain CSV ditolak', (await page.textContent('.flash--error')).includes('.csv'));

  // 9d. Fase 2: ringkasan
  await page.click('.mainnav a[href="/"]');
  const tile = async (label) => (await page.locator('.tile').filter({ has: page.locator('.tile__label', { hasText: new RegExp('^' + label + '$') }) }).locator('.tile__value').textContent()).trim();
  check('ringkasan: lead masuk 30 hari = 4', (await tile('Lead masuk')) === '4', await tile('Lead masuk'));
  check('ringkasan: closing 30 hari = 1', (await tile('Closing')) === '1');
  check('ringkasan: nilai closing dari data', (await tile('Nilai closing')) === 'Rp 12.500.000');
  check('ringkasan: konversi lead ke closing 25%', (await tile('Lead jadi closing')) === '25,0%');
  check('ringkasan: grafik 30 kolom hari', (await page.$$('.chart__hit')).length === 30);
  check('ringkasan: tooltip kolom berisi tanggal dan jumlah', /\d{2} \w{3}: \d+ lead/.test(await page.locator('.chart__hit').first().locator('title').textContent()));
  await page.click('.chart-table summary');
  check('ringkasan: tabel alternatif grafik bisa dibuka', await page.isVisible('.chart-table table'));
  check('ringkasan: tidak menampilkan angka spend/CPL tanpa data', !(await page.textContent('.tiles')).includes('CPL'));
  await page.click('.segmented a:text-is("7 hari")');
  check('ringkasan: periode 7 hari mengubah angka (2 lead)', (await tile('Lead masuk')) === '2', await tile('Lead masuk'));
  await page.click('.hbars__label:text-is("Closing")');
  check('ringkasan: klik stage membuka daftar terfilter', page.url().includes('stage=closing') && (await page.$$('.table tbody tr')).length === 1);

  // 10. Pipeline
  await page.goto(BASE + '/settings/pipeline');
  await page.fill('#new-label', 'Survey lokasi');
  await page.click('form .btn:has-text("Tambah")');
  check('stage baru ditambahkan', (await page.textContent('table')).includes('survey_lokasi'));
  await page.fill('#label-dihubungi', 'Sudah dihubungi');
  await page.click('button[form="pipeline-form"]');
  check('label stage diganti', (await page.inputValue('#label-dihubungi')) === 'Sudah dihubungi');
  await page.click('tr:has(code:text-is("survey_lokasi")) button:has-text("Hapus")');
  check('stage tanpa lead bisa dihapus', !(await page.textContent('table')).includes('survey_lokasi'));

  // 11. Log ingest + audit
  await page.goto(BASE + '/settings/ingest?status=rejected');
  check('log ditolak tercatat untuk perbaikan mapping', (await page.$$('.status--rejected')).length >= 1);
  await page.click('a[href^="/settings/ingest/log/"]');
  check('payload mentah bisa dilihat', (await page.textContent('.code-block')).includes('12345'));
  await page.goto(BASE + '/audit');
  const audit = await page.textContent('table');
  check('audit mencatat login, buat lead, ubah stage', ['login', 'lead_create', 'lead_stage', 'user_create'].every((a) => audit.includes(a)));

  // 11b. Fase 3: integrasi opsional GA4 + Search Console (pakai tests/mock-google.php)
  const openForm = async (prov) => {
    const d = page.locator(`section:has(#int-${prov}) details.int-form`).first();
    if (!(await d.evaluate((el) => el.open))) await d.locator('summary').click();
  };
  const runSync = () => execFileSync('php', [path.join(ROOT, 'cron/sync.php'), '-v'], { encoding: 'utf8' }).trim();
  await page.goto(BASE + '/sources');
  check('sumber: tanpa integrasi tampil belum tersambung', (await page.textContent('main')).includes('Google Analytics 4 belum tersambung'));
  check('sumber: cron tidak menyentuh tenant tanpa integrasi', runSync() === '');
  await page.click('a[href="/settings/integrations"]');
  check('integrasi: email service account tampil untuk diberikan ke klien', (await page.textContent('main')).includes('crm-sync@uji-lokal.iam.gserviceaccount.com'));
  await openForm('ga4');
  await page.fill('#acc-ga4', 'bukan-angka');
  await page.click('#acc-ga4 >> xpath=ancestor::form//button[@type="submit"]');
  check('integrasi: property ID tidak valid ditolak', (await page.textContent('.flash--error')).includes('Property ID tidak valid'));
  await openForm('ga4');
  await page.fill('#acc-ga4', '403403');
  await page.click('#acc-ga4 >> xpath=ancestor::form//button[@type="submit"]');
  await page.click('section:has(#int-ga4) button:text-is("Tes koneksi")');
  check('integrasi: tes koneksi tanpa akses memberi petunjuk', (await page.textContent('section:has(#int-ga4) .flash--error')).includes('belum diberi akses'));
  await openForm('ga4');
  await page.fill('#acc-ga4', 'properties/123456789');
  await page.click('#acc-ga4 >> xpath=ancestor::form//button[@type="submit"]');
  await page.click('section:has(#int-ga4) button:text-is("Tes koneksi")');
  check('integrasi: tes koneksi GA4 berhasil', (await page.textContent('section:has(#int-ga4) .flash--success')).includes('Sesi kemarin menurut GA4: 42'));
  check('integrasi: format properties/123 dinormalisasi', (await page.textContent('section:has(#int-ga4) dl')).includes('123456789'));
  await openForm('gsc');
  await page.fill('#acc-gsc', 'sc-domain:contoh.com');
  await page.click('#acc-gsc >> xpath=ancestor::form//button[@type="submit"]');
  let syncOut = runSync();
  check('sync: backfill 90 hari GA4 (180 baris)', syncOut.includes('provider=ga4 rows=180'), syncOut);
  check('sync: backfill GSC per halaman + query', /provider=gsc rows=\d+/.test(syncOut), syncOut);
  check('sync: tidak jalan lagi sebelum 60 menit', runSync() === '');
  await page.goto(BASE + '/settings/integrations');
  check('integrasi: status Aktif dan riwayat sync berhasil', (await page.textContent('section:has(#int-ga4) .status')).includes('Aktif') && (await page.$$('.table .status--processed')).length >= 2);
  await page.click('section:has(#int-ga4) button:text-is("Sync sekarang")');
  syncOut = runSync();
  check('sync: tombol Sync sekarang menjadwalkan ulang, upsert tanpa dobel', syncOut.includes('provider=ga4 rows=8'), syncOut);

  await page.goto(BASE + '/sources');
  const stile = async (label) => (await page.locator('.tile').filter({ has: page.locator('.tile__label', { hasText: new RegExp('^' + label + '$') }) }).locator('.tile__value').textContent()).trim();
  check('sumber: sesi 30 hari = 450 (tidak dobel setelah sync ulang)', (await stile('Sesi')) === '450', await stile('Sesi'));
  check('sumber: key events 30 hari = 90', (await stile('Key events \\(konversi GA4\\)')) === '90');
  check('sumber: dibanding periode lalu', (await page.textContent('main')).includes('+0% dari 450') || (await page.textContent('main')).includes('0% dari 450'));
  check('sumber: tabel source/medium', (await page.textContent('main')).includes('google / organic'));
  check('sumber: klik GSC dari total per halaman (140)', (await stile('Klik')) === '140', await stile('Klik'));
  check('sumber: posisi rata-rata tertimbang impresi (7,3)', (await stile('Posisi rata-rata')) === '7,3', await stile('Posisi rata-rata'));
  check('sumber: tabel kata kunci', (await page.textContent('main')).includes('kata a'));
  await page.click('.segmented a:text-is("7 hari")');
  check('sumber: periode 7 hari (sesi 105)', (await stile('Sesi')) === '105', await stile('Sesi'));

  // Gagal 2x berturut-turut: notifikasi Telegram (tercatat di notify.log karena bot belum dikonfigurasi)
  await page.goto(BASE + '/settings/integrations');
  await openForm('ga4');
  await page.fill('#acc-ga4', '403403');
  await page.click('#acc-ga4 >> xpath=ancestor::form//button[@type="submit"]');
  runSync();
  await page.click('section:has(#int-ga4) button:text-is("Sync sekarang")');
  runSync();
  const notifyLog = require('fs').readFileSync(path.join(ROOT, 'storage/logs/notify.log'), 'utf8');
  check('sync: gagal 2x berturut-turut memicu notifikasi', notifyLog.includes('Sync gagal 2x berturut-turut'));
  await page.goto(BASE + '/settings/integrations');
  check('integrasi: status Error dan pesan tampil', (await page.textContent('section:has(#int-ga4) .status')).includes('Error') && (await page.textContent('section:has(#int-ga4)')).includes('belum diberi akses'));
  await page.click('section:has(#int-ga4) button:text-is("Jeda")');
  await page.click('section:has(#int-ga4) button:text-is("Sync sekarang")').catch(() => {});
  check('integrasi: dijeda tidak disinkron', runSync() === '' && (await page.textContent('section:has(#int-ga4) .status')).includes('Dijeda'));
  await page.click('section:has(#int-gsc) summary:text-is("Putuskan sambungan")');
  await page.check('section:has(#int-gsc) input[name="delete_data"]');
  await page.click('section:has(#int-gsc) button:text-is("Putuskan")');
  await page.goto(BASE + '/sources');
  check('integrasi: putus + hapus data, GSC kembali belum tersambung', (await page.textContent('main')).includes('Search Console belum tersambung'));
  check('integrasi: GA4 dijeda, data lama tetap tampil', (await stile('Sesi')) === '450');

  // 12. Mobile
  const m = await browser.newContext({ viewport: { width: 375, height: 800 }, isMobile: true });
  const mp = await m.newPage();
  mp.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push('[mobile] ' + msg.text()); });
  await m.addCookies(await ctx.cookies());
  for (const p of ['/', '/?days=90', '/sources', '/settings/integrations', '/leads?view=kanban', '/leads/import', '/leads', leadUrl.replace(BASE, ''), '/settings/users', '/settings/pipeline', '/settings/ingest', sourceUrl.replace(BASE, ''), '/tenants', '/audit', '/account']) {
    await mp.goto(BASE + p);
    await noOverflow(mp, p);
  }
  const small = await mp.$$eval('button, .btn', (els) => els.filter((e) => e.offsetParent && e.getBoundingClientRect().height < 44).map((e) => e.textContent.trim()));
  check('tombol minimal 44px di mobile', small.length === 0, small.join(', '));

  // 13. Login sebagai sales: hak akses dan isolasi tenant
  const s = await browser.newContext();
  const sp = await s.newPage();
  await sp.goto(BASE + '/login');
  await sp.fill('#login', 'sales1');
  await sp.fill('#password', salesPass);
  await sp.click('button[type=submit]');
  check('sales login tanpa 2FA', sp.url() === BASE + '/', sp.url());
  let resp = await sp.goto(BASE + '/leads/import');
  check('sales tidak bisa import CSV (403)', resp.status() === 403);
  await sp.goto(BASE + '/leads');
  check('tombol import tidak tampil untuk sales', (await sp.$('a[href="/leads/import"]')) === null);
  resp = await sp.goto(BASE + '/settings/users');
  check('sales tidak bisa buka pengaturan (403)', resp.status() === 403);
  resp = await sp.goto(BASE + '/settings/integrations');
  check('sales tidak bisa buka pengaturan integrasi (403)', resp.status() === 403);
  resp = await sp.goto(BASE + '/sources');
  check('sales bisa lihat halaman Sumber', resp.status() === 200 && (await sp.textContent('main')).includes('google / organic'));
  resp = await sp.goto(BASE + '/audit');
  check('sales tidak bisa buka audit (403)', resp.status() === 403);
  check('menu sales tidak menampilkan link yang tidak boleh diakses', !(await sp.$('a[href="/settings"]')) && !(await sp.$('a[href="/tenants"]')));

  // Tenant kedua: lead tenant pertama tidak boleh terbuka
  await page.goto(BASE + '/tenants');
  await page.fill('#t-name', 'Tenant Dua');
  await page.fill('#t-slug', 'tenant-dua');
  await page.click('form[action="/tenants"] button[type=submit]');
  expectedErrorPages.add(leadUrl);
  resp = await page.goto(leadUrl);
  check('lead tenant lain tidak bisa dibuka setelah pindah tenant (404)', resp.status() === 404);
  await page.goto(BASE + '/leads');
  check('daftar lead tenant baru kosong', (await page.textContent('.empty')).includes('Belum ada lead'));
  await page.goto(BASE + '/sources');
  check('data GA4 tenant lain tidak bocor ke tenant baru', (await page.textContent('main')).includes('Google Analytics 4 belum tersambung'));

  // 14. CSRF
  const csrfResp = await sp.request.post(BASE + '/leads', { form: { phone: '081200001111' } });
  check('POST tanpa token CSRF ditolak', csrfResp.status() === 419, String(csrfResp.status()));

  // 15. Logout lalu login ulang dengan 2FA
  await page.click('form[action="/logout"] button');
  check('logout kembali ke login', page.url().endsWith('/login'));
  await page.fill('#login', SUPER_USER);
  await page.fill('#password', SUPER_PASS);
  await page.click('button[type=submit]');
  check('login ulang superadmin minta kode 2FA', page.url().endsWith('/login/2fa'));
  await waitNextStep();
  await page.fill('#code', totp(secret));
  await page.click('form[action="/login/2fa"] button');
  check('login dengan kode 2FA berhasil', page.url() === BASE + '/', page.url());

  check('tidak ada console error', consoleErrors.length === 0, consoleErrors.join(' | '));
  await browser.close();
  const failed = results.filter((x) => !x.ok);
  console.log(`\n${results.length - failed.length}/${results.length} PASS`);
  process.exit(failed.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
