// Uji end-to-end fitur kuitansi pembayaran (docs/KUITANSI.md) dengan Playwright terhadap server lokal.
// Pakai DB bersih yang hanya berisi superadmin (lihat docs/DEPLOY.md bagian testing), plus server tiruan:
//   php -S 127.0.0.1:8091 tests/mock-outbox.php
// dan di config lokal: 'brevo' => ['endpoint' => 'http://127.0.0.1:8091/brevo'], 'meta' => ['graph_url' => 'http://127.0.0.1:8091/meta']
// Lalu: SUPER_PASS=... node tests/e2e-kuitansi.js
const { chromium } = require('playwright');
const crypto = require('crypto');
const fs = require('fs');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const SUPER_USER = process.env.SUPER_USER || 'admin';
const SUPER_PASS = process.env.SUPER_PASS;
const ROOT = path.resolve(__dirname, '..');
const MOCK_LOG = path.join(ROOT, 'storage/test/outbox-requests.jsonl');
// SHOTS_DIR=folder: simpan screenshot halaman utama untuk dicek mata (opsional).
const SHOTS = process.env.SHOTS_DIR;
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: true }); };

function base32Decode(s) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of s.replace(/[^A-Z2-7]/gi, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}
function totp(secret) {
  const buf = Buffer.alloc(8);
  buf.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const h = crypto.createHmac('sha1', base32Decode(secret)).update(buf).digest();
  const o = h[19] & 0xf;
  const v = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];
  return String(v % 1000000).padStart(6, '0');
}
const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  (' + detail + ')' : ''}`);
}
const php = (file) => execFileSync('php', [path.join(ROOT, file), '-v'], { encoding: 'utf8' }).trim();
const runIngest = () => php('cron/ingest-worker.php');
const runOutbox = () => php('cron/outbox-worker.php');
/** Query DB lewat bootstrap aplikasi (hanya untuk menyiapkan/mengecek kondisi tes). */
function sql(query, params = []) {
  const code = `require '${path.join(ROOT, 'lib/bootstrap.php')}'; $s = q($argv[1], json_decode($argv[2], true)); echo json_encode($s->columnCount() ? $s->fetchAll() : []);`;
  const out = execFileSync('php', ['-r', code, query, JSON.stringify(params)], { encoding: 'utf8' });
  return JSON.parse(out || '[]');
}
function mockRequests() {
  if (!fs.existsSync(MOCK_LOG)) return [];
  return fs.readFileSync(MOCK_LOG, 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l));
}
async function noOverflow(page, label) {
  const o = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check(`mobile tanpa overflow horizontal: ${label}`, o <= 0, `selisih ${o}px`);
}
async function smallButtons(page) {
  return page.evaluate(() => [...document.querySelectorAll('button, .btn, summary.btn')]
    .filter((b) => b.offsetParent !== null && b.getBoundingClientRect().height < 44)
    .map((b) => b.textContent.trim()));
}

(async () => {
  if (!SUPER_PASS) throw new Error('SUPER_PASS wajib diisi');
  if (fs.existsSync(MOCK_LOG)) fs.unlinkSync(MOCK_LOG);
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const consoleErrors = [];
  const expected404 = new Set();
  page.on('console', (m) => {
    if (m.type() === 'error' && !expected404.has(m.location().url)) consoleErrors.push(m.text() + ' @ ' + m.location().url);
  });
  page.on('pageerror', (e) => consoleErrors.push(e.message));
  page.on('dialog', (d) => d.accept());

  // 1. Superadmin + 2FA + tenant
  await page.goto(BASE + '/login');
  await page.fill('#login', SUPER_USER);
  await page.fill('#password', SUPER_PASS);
  await page.click('button[type=submit]');
  const secret = (await page.getAttribute('[data-copy]', 'data-copy')).trim();
  await page.fill('#code', totp(secret));
  await page.click('form[action="/login/2fa-setup"] button');
  await page.fill('#t-name', 'Bisnis A');
  await page.fill('#t-slug', 'bisnis-a');
  await page.click('form[action="/tenants"] button[type=submit]');
  check('tenant uji dibuat', (await page.textContent('.brand__tenant')).includes('Bisnis A'));

  // 2. Pengaturan kuitansi + email
  await page.goto(BASE + '/settings/receipts');
  check('tab Kuitansi & iklan ada di pengaturan', (await page.textContent('.tabs')).includes('Kuitansi'));
  check('status email awal belum lengkap', (await page.textContent('main')).includes('Belum lengkap'));
  await page.fill('#receipt_business_name', 'Usaha Contoh');
  await page.fill('#receipt_contact', 'WA 0812-0000-0000');
  await page.fill('#receipt_accent', '#ff2d78');
  await page.fill('#receipt_prefix', 'kw');
  await page.click('form:has(input[value=receipt]) button[type=submit]');
  check('pengaturan kuitansi tersimpan', (await page.textContent('.flash--success')).includes('tersimpan'));
  check('aksen terang digelapkan otomatis', (await page.textContent('#accent-help')).includes('digelapkan'));
  check('kalimat kaki bawaan terisi', (await page.inputValue('#receipt_footer')).includes('sah tanpa tanda tangan basah'));
  await page.fill('#receipt_accent', 'merah');
  await page.click('form:has(input[value=receipt]) button[type=submit]');
  check('warna bukan hex ditolak', (await page.textContent('.flash--error')).includes('hex'));
  await page.fill('#mail_from_email', 'kuitansi@contoh.com');
  await page.fill('#mail_from_name', 'Usaha Contoh');
  await page.fill('#brevo_api_key', 'kunci-uji');
  await page.fill('#brevo_webhook_token', 'token-uji-123');
  await page.click('form:has(input[value=email]) button[type=submit]');
  check('pengaturan email tersimpan dan siap kirim', (await page.textContent('main')).includes('Siap kirim'));
  check('rahasia tidak ditampilkan ulang', (await page.inputValue('#brevo_api_key')) === '' && (await page.content()).indexOf('kunci-uji') === -1);
  const stored = sql("SELECT value FROM tenant_settings WHERE setting_key = 'brevo_api_key'");
  check('API key tersimpan terenkripsi di DB', stored.length === 1 && stored[0].value !== 'kunci-uji');
  check('URL webhook Brevo tampil', (await page.textContent('main')).includes('/hooks/brevo/bisnis-a'));

  // 3. Sumber webhook booking + lead masuk dengan email, opt-in, cookie Meta, event_id
  await page.goto(BASE + '/settings/ingest');
  await page.fill('#key-name', 'Form booking');
  await page.click('form[action="/settings/ingest/keys"] button');
  const token = (await page.getAttribute('.reveal [data-copy]', 'data-copy')).trim();
  await page.fill('#src-label', 'Form booking');
  await page.fill('#src-slug', 'booking');
  await page.selectOption('#src-adapter', 'generic');
  await page.click('form[action="/settings/ingest/sources"] button');
  const send = (body) => fetch(BASE + '/ingest.php?t=bisnis-a&s=booking', {
    method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token }, body: JSON.stringify(body),
  });
  let r = await send({ id: 'bk-1', phone: '0812 7000 1111', name: 'Rina', email: 'Rina@Contoh.com', receipt_optin: true,
    product: 'Wedding', city: 'Jakarta', fbp: 'fb.1.1700000000000.123456', fbc: 'fb.1.1700000000000.AbCd', event_id: 'evt-booking-0001',
    message: 'Tanggal 12 Des 2026, konsep garden', landing_url: 'https://contoh.com/#booking' });
  check('booking dengan email diterima', r.status === 200, String(r.status));
  r = await send({ id: 'bk-2', phone: '0812 7000 2222', name: 'Doni', email: 'doni@contoh.com', receipt_optin: false, event_id: 'evt-booking-0002' });
  r = await send({ id: 'bk-3', phone: '0812 7000 3333', name: 'Eka', receipt_optin: true, email: 'bukan-email' });
  check('worker ingest memproses 3 booking', runIngest().includes('processed=3'));
  const leads = sql('SELECT id, phone, email, receipt_optin_at, fbp, fbc, lead_event_id, product, city FROM leads ORDER BY id');
  const rina = leads.find((l) => l.phone === '6281270001111');
  const doni = leads.find((l) => l.phone === '6281270002222');
  const eka = leads.find((l) => l.phone === '6281270003333');
  check('email disimpan huruf kecil', rina.email === 'rina@contoh.com');
  check('opt-in kuitansi tercatat', rina.receipt_optin_at !== null);
  check('tanpa centang, opt-in kosong', doni.receipt_optin_at === null && doni.email === 'doni@contoh.com');
  check('email tidak valid diabaikan, opt-in tidak dicatat', eka.email === null && eka.receipt_optin_at === null);
  check('fbp, fbc, event_id, produk, kota tersimpan', rina.fbp && rina.fbc && rina.lead_event_id === 'evt-booking-0001' && rina.product === 'Wedding' && rina.city === 'Jakarta');
  let conv = sql("SELECT event_name, status FROM conversion_events WHERE lead_id = ?", [rina.id]);
  check('event Lead dicatat menunggu pengaturan Meta', conv.length === 1 && conv[0].event_name === 'Lead' && conv[0].status === 'waiting_config');
  r = await send({ id: 'bk-1b', phone: '0812 7000 1111', event_id: 'evt-booking-0001' });
  runIngest();
  check('event_id sama tidak dobel', sql("SELECT COUNT(*) AS n FROM conversion_events WHERE lead_id = ?", [rina.id])[0].n === 1);

  // 4. Detail lead: email, opt-in, kartu pembayaran
  await page.goto(BASE + '/leads/' + rina.id);
  check('email tampil di detail lead', (await page.inputValue('#email')) === 'rina@contoh.com');
  check('centang opt-in tercentang', await page.isChecked('input[name=receipt_optin]'));
  check('kartu pembayaran menyatakan kuitansi dikirim ke email', (await page.textContent('#pembayaran')).includes('Ya, ke rina@contoh.com'));
  check('aktivitas mencatat permintaan kuitansi', (await page.textContent('.timeline')).includes('minta kuitansi lewat email'));
  check('konversi Lead tampil di detail', (await page.textContent('aside')).includes('Menunggu pengaturan Meta'));
  await page.fill('#deal_value', '1.500.000');
  await page.click(`form[action="/leads/${rina.id}"] button[type=submit]`);

  // Validasi
  await page.fill('#pay_amount', '0');
  await page.click(`form[action="/leads/${rina.id}/payments"] button[type=submit]`);
  check('nominal 0 ditolak', (await page.textContent('.flash--error')).includes('Nominal'));
  await page.fill('#pay_amount', '500000');
  await page.fill('#pay_date', '2099-01-01');
  await page.click(`form[action="/leads/${rina.id}/payments"] button[type=submit]`);
  check('tanggal masa depan ditolak', (await page.textContent('.flash--error')).includes('masa depan'));
  check('isian form tetap ada setelah gagal', (await page.inputValue('#pay_amount')) === '500000');

  // 5. Catat DP
  const today = new Date(Date.now() + 7 * 3600e3).toISOString().slice(0, 10);
  await page.fill('#pay_date', today);
  await page.selectOption('#pay_kind', 'dp');
  await page.selectOption('#pay_method', 'Transfer bank');
  await page.fill('#pay_desc', 'DP MC Wedding 12 Des 2026, Jakarta');
  await page.click(`form[action="/leads/${rina.id}/payments"] button[type=submit]`);
  const flash = await page.textContent('.flash--success');
  const ym = today.slice(0, 7).replace('-', '/');
  check('DP tersimpan dengan nomor kuitansi pertama', flash.includes(`KW/${ym}/0001`), flash);
  check('flash menyebut email kuitansi dikirim', flash.includes('rina@contoh.com'));
  check('sisa tagihan dihitung', (await page.textContent('#pembayaran')).includes('Rp 1.000.000'));
  check('status email menunggu kirim', (await page.textContent('.payments')).includes('Menunggu kirim'));

  // 6. Worker kirim email
  let out = runOutbox();
  check('worker outbox mengirim email', out.includes('email=1'), out);
  let reqs = mockRequests().filter((q) => q.path === '/brevo');
  const mail = reqs[0] && reqs[0].body;
  check('Brevo dipanggil dengan API key', reqs.length === 1 && reqs[0].has_api_key);
  check('email ke alamat klien dari pengirim tenant', mail.to[0].email === 'rina@contoh.com' && mail.sender.email === 'kuitansi@contoh.com');
  check('subjek memuat nomor kuitansi', mail.subject.includes(`KW/${ym}/0001`));
  check('isi email memuat terbilang', mail.htmlContent.includes('Lima ratus ribu rupiah') && mail.textContent.includes('lima ratus ribu rupiah'));
  check('isi email memuat link kuitansi', /\/r\/[a-f0-9]{64}/.test(mail.htmlContent));
  check('email HTML tanpa em dash', !mail.htmlContent.includes('—'));
  await page.reload();
  check('status email jadi terkirim ke Brevo', (await page.textContent('.payments')).includes('Terkirim ke Brevo'));

  // 7. Halaman kuitansi publik
  const pay1 = sql('SELECT * FROM payments ORDER BY id LIMIT 1')[0];
  const pub = await ctx.newPage();
  pub.on('console', (m) => { if (m.type() === 'error' && !expected404.has(m.location().url)) consoleErrors.push(m.text() + ' @ ' + m.location().url); });
  const resp = await pub.goto(BASE + '/r/' + pay1.public_token);
  check('kuitansi publik terbuka tanpa login', resp.status() === 200);
  check('header noindex', (resp.headers()['x-robots-tag'] || '').includes('noindex'));
  const pubText = await pub.textContent('main');
  check('kuitansi memuat nomor, nama usaha, terbilang', pubText.includes(`KW/${ym}/0001`) && pubText.includes('Usaha Contoh') && pubText.includes('Lima ratus ribu rupiah'));
  check('kuitansi memuat ringkasan sisa', pubText.includes('Rp 1.000.000'));
  check('kaki kuitansi tampil', pubText.includes('sah tanpa tanda tangan basah'));
  check('nomor HP klien tidak tampil di kuitansi publik', !pubText.includes('6281270001111'));
  const accentCss = await (await fetch(BASE + '/kuitansi-tema/bisnis-a.css')).text();
  check('CSS aksen tenant tersedia', accentCss.includes('--r-accent'));
  check('tombol cetak ada', await pub.isVisible('[data-print]'));
  await shot(pub, 'kuitansi-desktop');
  await pub.setViewportSize({ width: 360, height: 800 });
  await noOverflow(pub, '/r/token');
  await shot(pub, 'kuitansi-hp');
  const small = await smallButtons(pub);
  check('tombol kuitansi minimal 44px di mobile', small.length === 0, small.join(', '));
  await pub.emulateMedia({ media: 'print' });
  check('tombol cetak tersembunyi saat print', !(await pub.isVisible('[data-print]')));
  await pub.emulateMedia({ media: 'screen' });
  expected404.add(BASE + '/r/' + 'a'.repeat(64));
  const bad = await pub.goto(BASE + '/r/' + 'a'.repeat(64));
  check('token tidak dikenal 404', bad.status() === 404);
  await pub.close();

  // 8. Pelunasan: nomor berikutnya, sisa 0
  await page.selectOption('#pay_kind', 'pelunasan');
  await page.fill('#pay_amount', '1000000');
  await page.fill('#pay_date', today);
  await page.click(`form[action="/leads/${rina.id}/payments"] button[type=submit]`);
  check('pelunasan dapat nomor 0002', (await page.textContent('.flash--success')).includes(`KW/${ym}/0002`));
  check('sisa jadi Rp 0', (await page.textContent('#pembayaran')).includes('Rp 0'));
  await shot(page, 'lead-desktop');
  runOutbox();

  // 9. Webhook status Brevo
  const msgs = sql('SELECT id, provider_msg_id FROM email_messages ORDER BY id');
  const hook = (body, tok = 'token-uji-123') => fetch(BASE + '/hooks/brevo/bisnis-a', {
    method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok }, body: JSON.stringify(body),
  });
  r = await hook({ event: 'delivered', 'message-id': msgs[0].provider_msg_id }, 'salah');
  check('webhook token salah ditolak 401', r.status === 401, String(r.status));
  r = await hook({ event: 'delivered', 'message-id': msgs[0].provider_msg_id });
  check('webhook tanpa CSRF diterima', r.status === 200, String(r.status));
  await hook({ event: 'opened', 'message-id': msgs[0].provider_msg_id });
  await hook({ event: 'delivered', 'message-id': msgs[0].provider_msg_id });
  check('status tidak turun dari dibuka ke sampai', sql('SELECT status FROM email_messages WHERE id = ?', [msgs[0].id])[0].status === 'opened');
  r = await hook([{ event: 'hard_bounce', 'message-id': msgs[1].provider_msg_id, reason: 'mailbox not found' }]);
  check('webhook menerima array event', (await r.json()).processed === 1);
  await page.reload();
  const payText = await page.textContent('.payments');
  check('status Dibuka dan Mental tampil', payText.includes('Dibuka') && payText.includes('Mental'));
  check('email mental ditandai di lead', (await page.textContent('#pembayaran')).includes('email mental'));
  r = await hook({ event: 'delivered', 'message-id': '<tidak-ada@x>' });
  check('message-id asing diabaikan tanpa error', r.status === 200);

  // 10. Lead tanpa opt-in: tidak dikirim otomatis, bisa kirim manual
  await page.goto(BASE + '/leads/' + doni.id);
  check('lead tanpa persetujuan: kuitansi tidak dikirim', (await page.textContent('#pembayaran')).includes('klien belum setuju'));
  await page.fill('#pay_amount', '250000');
  await page.click(`form[action="/leads/${doni.id}/payments"] button[type=submit]`);
  check('pembayaran tanpa opt-in tidak masuk antrian email', (await page.textContent('.payments')).includes('Tidak dikirim'));
  await page.click('form[action$="/resend"] button');
  check('kirim email manual masuk antrian', (await page.textContent('.flash--success')).includes('antrian'));
  check('kirim manual mengirim email', runOutbox().includes('email=1'));

  // 11. Batalkan kuitansi (admin)
  await page.click('.void-box summary');
  await page.fill('.void-box input[name=void_reason]', 'Salah nominal');
  await page.click('.void-box button[type=submit]');
  check('kuitansi dibatalkan', (await page.textContent('.payments')).includes('Batal'));
  const payDoni = sql('SELECT public_token, id FROM payments WHERE lead_id = ?', [doni.id])[0];
  check('konversi Purchase kuitansi batal ikut dibatalkan', sql('SELECT status FROM conversion_events WHERE payment_id = ?', [payDoni.id])[0].status === 'cancelled');
  const voidPage = await ctx.newPage();
  await voidPage.goto(BASE + '/r/' + payDoni.public_token);
  check('kuitansi publik menandai batal', (await voidPage.textContent('main')).includes('dibatalkan'));
  await voidPage.close();

  // 12. Hak akses: sales boleh catat, tidak boleh batalkan
  await page.goto(BASE + '/settings/users');
  await page.fill('#u-name', 'Sales Satu');
  await page.fill('#u-username', 'sales1');
  await page.selectOption('#u-role', 'sales');
  await page.click('form[action="/settings/users"] button[type=submit]');
  const salesPass = (await page.getAttribute('.reveal [data-copy]', 'data-copy')).trim();
  const sctx = await browser.newContext();
  const sp = await sctx.newPage();
  sp.on('console', (m) => { if (m.type() === 'error' && !m.text().includes('403')) consoleErrors.push(m.text() + ' @ ' + m.location().url); });
  await sp.goto(BASE + '/login');
  await sp.fill('#login', 'sales1');
  await sp.fill('#password', salesPass);
  await sp.click('button[type=submit]');
  if (sp.url().includes('password')) {
    // Akun baru bisa diminta ganti password dulu.
    await sp.fill('#new_password', 'password-sales-baru-123');
    await sp.fill('#new_password2', 'password-sales-baru-123');
    await sp.click('form button[type=submit]');
  }
  await sp.goto(BASE + '/leads/' + eka.id);
  check('sales melihat form catat pembayaran', await sp.isVisible('#pay_amount'));
  await sp.fill('#pay_amount', '100000');
  await sp.click(`form[action="/leads/${eka.id}/payments"] button[type=submit]`);
  check('sales bisa catat pembayaran', (await sp.textContent('.flash--success')).includes('tersimpan'));
  check('sales tidak melihat tombol batalkan', (await sp.$('.void-box')) === null);
  const payEka = sql('SELECT id FROM payments WHERE lead_id = ?', [eka.id])[0];
  const csrf = await sp.getAttribute('input[name=_csrf]', 'value');
  const voidResp = await sp.request.post(BASE + `/payments/${payEka.id}/void`, { form: { _csrf: csrf, void_reason: 'x' } });
  check('sales dilarang membatalkan (403)', voidResp.status() === 403, String(voidResp.status()));
  const setResp = await sp.goto(BASE + '/settings/receipts');
  check('sales tidak bisa buka pengaturan kuitansi (403)', setResp.status() === 403);
  await sctx.close();

  // 13. Meta: event menunggu, lalu terkirim setelah pengaturan diisi
  sql("UPDATE conversion_events SET event_time = UTC_TIMESTAMP() - INTERVAL 9 DAY WHERE lead_id = ? AND event_name = 'Lead'", [doni.id]);
  check('worker tidak mengirim Meta sebelum pengaturan ada', runOutbox().includes('conversion=0'));
  await page.goto(BASE + '/settings/receipts');
  check('jumlah event menunggu tampil', /\d+ event menunggu/.test(await page.textContent('main')));
  await page.fill('#meta_dataset_id', 'abc');
  await page.click('form:has(input[value=meta]) button[type=submit]');
  check('ID dataset bukan angka ditolak', (await page.textContent('.flash--error')).includes('angka'));
  await page.fill('#meta_dataset_id', '123456789012345');
  await page.fill('#meta_access_token', 'token-meta-uji');
  await page.fill('#meta_test_event_code', 'TEST123');
  await page.click('form:has(input[value=meta]) button[type=submit]');
  check('status Meta aktif', (await page.textContent('main')).includes('Aktif'));
  await shot(page, 'pengaturan-desktop');
  out = runOutbox();
  const metaReqs = mockRequests().filter((q) => q.path.startsWith('/meta/'));
  const events = metaReqs.flatMap((q) => q.body.data);
  check('event Lead dan Purchase terkirim ke dataset', metaReqs.every((q) => q.path === '/meta/123456789012345/events' && q.has_token)
    && events.some((e) => e.event_name === 'Lead') && events.some((e) => e.event_name === 'Purchase'), out);
  const lead = events.find((e) => e.event_name === 'Lead' && e.event_id === 'evt-booking-0001');
  check('Lead memakai event_id form untuk dedup', !!lead);
  check('email dan HP di-hash SHA-256', lead.user_data.em[0] === crypto.createHash('sha256').update('rina@contoh.com').digest('hex')
    && lead.user_data.ph[0] === crypto.createHash('sha256').update('6281270001111').digest('hex'));
  check('fbp dan fbc ikut', lead.user_data.fbp === 'fb.1.1700000000000.123456' && lead.user_data.fbc === 'fb.1.1700000000000.AbCd');
  const purchase = events.find((e) => e.event_name === 'Purchase' && e.custom_data.value === 500000);
  check('Purchase membawa nilai DP dan IDR', !!purchase && purchase.custom_data.currency === 'IDR' && purchase.action_source === 'system_generated');
  check('test_event_code ikut dikirim', metaReqs.every((q) => q.body.test_event_code === 'TEST123'));
  check('event lebih dari 7 hari ditandai kedaluwarsa', sql("SELECT status FROM conversion_events WHERE lead_id = ? AND event_name = 'Lead'", [doni.id])[0].status === 'expired');
  check('event batal tidak dikirim', !events.some((e) => e.event_id === 'pay-' + payDoni.id));
  await page.goto(BASE + '/leads/' + rina.id);
  check('status konversi Terkirim tampil di lead', (await page.textContent('aside')).includes('Terkirim'));

  // 14. API key Brevo salah: email gagal permanen, tidak diulang terus
  await page.goto(BASE + '/settings/receipts');
  await page.fill('#brevo_api_key', 'kunci-salah');
  await page.click('form:has(input[value=email]) button[type=submit]');
  await page.goto(BASE + '/leads/' + rina.id);
  await page.click('form[action$="/resend"] >> nth=0');
  runOutbox();
  check('API key salah: status Gagal', sql("SELECT status FROM email_messages ORDER BY id DESC LIMIT 1")[0].status === 'failed');

  // 15. Mobile dan console
  await page.setViewportSize({ width: 360, height: 800 });
  for (const url of ['/leads/' + rina.id, '/settings/receipts']) {
    await page.goto(BASE + url);
    await noOverflow(page, url);
    await shot(page, 'hp' + url.replace(/\//g, '-'));
    const tiny = await smallButtons(page);
    check(`tombol minimal 44px: ${url}`, tiny.length === 0, tiny.join(', '));
  }
  check('tidak ada console error', consoleErrors.length === 0, consoleErrors.join(' | '));

  await browser.close();
  const failed = results.filter((x) => !x.ok).length;
  console.log(`\n${results.length - failed}/${results.length} PASS`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
