// Tes feed konversi offline Google Ads + label sumber Meta (IG/FB). Butuh DB bersih (tests/reset-local.sh)
// dan server di BASE. Jalankan: SUPER_PASS=... node tests/e2e-google-ads.js
const { chromium } = require('playwright');
const crypto = require('crypto');
const path = require('path');
const { execFileSync } = require('child_process');

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
function sql(query, params = []) {
  const code = `require '${path.join(ROOT, 'lib/bootstrap.php')}'; $s = q($argv[1], json_decode($argv[2], true)); echo json_encode($s->columnCount() ? $s->fetchAll() : []);`;
  return JSON.parse(execFileSync('php', ['-r', code, query, JSON.stringify(params)], { encoding: 'utf8' }) || '[]');
}
const feed = (slug, user, pass) => fetch(`${BASE}/feeds/google-ads/${slug}.csv`, {
  headers: user === null ? {} : { Authorization: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64') },
});
const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');

(async () => {
  if (!SUPER_PASS) throw new Error('SUPER_PASS wajib diisi');
  const browser = await chromium.launch();
  const page = await (await browser.newContext()).newPage();
  const consoleErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', (e) => consoleErrors.push(e.message));

  // Superadmin + 2FA + tenant
  await page.goto(BASE + '/login');
  await page.fill('#login', SUPER_USER);
  await page.fill('#password', SUPER_PASS);
  await page.click('button[type=submit]');
  const secret = (await page.getAttribute('[data-copy]', 'data-copy')).trim();
  await page.fill('#code', totp(secret));
  await page.click('form[action="/login/2fa-setup"] button');
  await page.fill('#t-name', 'Klien Iklan');
  await page.fill('#t-slug', 'klien-iklan');
  await page.click('form[action="/tenants"] button[type=submit]');

  // Lead manual dari WA, sumber dipilih admin
  await page.goto(BASE + '/leads/new');
  const labels = await page.$$eval('#source option', (o) => o.map((x) => x.textContent.trim()));
  check('pilihan sumber Meta (IG/FB) ada', labels.includes('Meta (IG/FB)'), labels.join(', '));
  check('label lama Click to WhatsApp sudah tidak ada', !labels.includes('Click to WhatsApp'));
  await page.fill('#phone', '0812 3456 7890');
  await page.fill('#name', 'Budi');
  await page.selectOption('#source', 'ctwa');
  await page.click('form[action="/leads"] button');
  const lead = sql("SELECT id, source FROM leads WHERE name = 'Budi'")[0];
  check('lead manual tersimpan dengan sumber ctwa', lead && lead.source === 'ctwa');

  // Pembayaran
  const today = new Date(Date.now() + 7 * 3600e3).toISOString().slice(0, 10);
  await page.goto(BASE + '/leads/' + lead.id);
  await page.fill('#pay_amount', '2500000');
  await page.fill('#pay_date', today);
  await page.selectOption('#pay_kind', 'pelunasan');
  await page.selectOption('#pay_method', 'Transfer bank');
  await page.fill('#pay_desc', 'Smart film 2 jendela');
  await page.click(`form[action="/leads/${lead.id}/payments"] button[type=submit]`);
  const receipt = sql('SELECT receipt_no FROM payments WHERE lead_id = ?', [lead.id])[0].receipt_no;
  check('pembayaran tercatat', !!receipt, receipt);

  // Pengaturan Google Ads
  await page.goto(BASE + '/settings/receipts');
  const gads = 'form:has(input[value=google])';
  check('bagian Google Ads tampil', (await page.textContent(gads)).includes('Konversi offline ke Google Ads'));
  check('status awal Belum diisi', (await page.textContent(gads)).includes('Belum diisi'));
  check('feed belum bisa diakses sebelum diatur', (await feed('klien-iklan', 'klien-iklan', '')).status === 401);
  await page.fill('#gads_conversion_name', 'Deal CRM');
  await page.click(`${gads} button[type=submit]`);
  check('pengaturan Google Ads tersimpan', (await page.textContent('.flash--success')).includes('tersimpan'));
  check('status jadi Aktif', (await page.textContent(gads)).includes('Aktif'));
  const pass1 = await page.getAttribute(`${gads} [data-copy]:not([data-copy^="http"])`, 'data-copy');
  const url = await page.getAttribute(`${gads} [data-copy^="http"]`, 'data-copy');
  check('alamat feed sesuai tenant', url.endsWith('/feeds/google-ads/klien-iklan.csv'), url);
  check('password feed dibuat aplikasi (32 hex)', /^[0-9a-f]{32}$/.test(pass1 || ''));

  // Isi feed
  const r = await feed('klien-iklan', 'klien-iklan', pass1);
  const body = await r.text();
  const lines = body.trim().split('\n');
  check('feed 200 dengan password benar', r.status === 200, String(r.status));
  check('content-type CSV', (r.headers.get('content-type') || '').startsWith('text/csv'));
  check('baris pertama langsung judul kolom (Data Manager)', lines[0].startsWith('"Google Click ID"') && lines[0].includes('"Phone Number"') && lines[0].includes('"Order ID"'));
  check('satu baris konversi', lines.length === 2, String(lines.length));
  check('HP di-hash format E.164', body.includes(sha('+6281234567890')));
  check('nomor HP mentah tidak bocor', !body.includes('81234567890,') && !body.includes('0812'));
  check('nama konversi, nilai, IDR, order id', lines[1].includes('"Deal CRM"') && lines[1].includes('2500000.00,IDR,' + receipt));
  check('waktu konversi zona +0700', /\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\+0700/.test(lines[1]));

  // Akses ditolak
  check('password salah 401', (await feed('klien-iklan', 'klien-iklan', 'salah')).status === 401);
  check('tanpa auth 401', (await feed('klien-iklan', null)).status === 401);
  check('username tenant lain 401', (await feed('klien-iklan', 'bisnis-a', pass1)).status === 401);
  check('tenant tidak ada 401', (await feed('tidak-ada', 'tidak-ada', pass1)).status === 401);

  // Ganti password
  await page.check('input[name=new_gads_feed_password]');
  await page.click(`${gads} button[type=submit]`);
  const pass2 = await page.getAttribute(`${gads} [data-copy]:not([data-copy^="http"])`, 'data-copy');
  check('password baru berbeda', pass2 && pass2 !== pass1);
  check('password lama tidak berlaku', (await feed('klien-iklan', 'klien-iklan', pass1)).status === 401);
  check('password baru berlaku', (await feed('klien-iklan', 'klien-iklan', pass2)).status === 200);

  // Kuitansi batal tidak masuk feed
  sql('UPDATE payments SET voided_at = UTC_TIMESTAMP(), void_reason = ? WHERE lead_id = ?', ['uji', lead.id]);
  const after = (await (await feed('klien-iklan', 'klien-iklan', pass2)).text()).trim().split('\n');
  check('kuitansi batal tidak masuk feed', after.length === 1, String(after.length));

  // Tampilan HP
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(BASE + '/settings/receipts');
  const o = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('mobile tanpa overflow horizontal: /settings/receipts', o <= 0, `selisih ${o}px`);
  check('tidak ada console error', consoleErrors.length === 0, consoleErrors.join(' | '));

  await browser.close();
  const failed = results.filter((x) => !x.ok).length;
  console.log(`\n${results.length - failed}/${results.length} PASS`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
