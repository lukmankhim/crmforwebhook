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

  // 12. Mobile
  const m = await browser.newContext({ viewport: { width: 375, height: 800 }, isMobile: true });
  const mp = await m.newPage();
  mp.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push('[mobile] ' + msg.text()); });
  await m.addCookies(await ctx.cookies());
  for (const p of ['/leads', leadUrl.replace(BASE, ''), '/settings/users', '/settings/pipeline', '/settings/ingest', sourceUrl.replace(BASE, ''), '/tenants', '/audit', '/account']) {
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
  check('sales login tanpa 2FA', sp.url().endsWith('/leads'), sp.url());
  let resp = await sp.goto(BASE + '/settings/users');
  check('sales tidak bisa buka pengaturan (403)', resp.status() === 403);
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
  check('login dengan kode 2FA berhasil', page.url().endsWith('/leads'), page.url());

  check('tidak ada console error', consoleErrors.length === 0, consoleErrors.join(' | '));
  await browser.close();
  const failed = results.filter((x) => !x.ok);
  console.log(`\n${results.length - failed.length}/${results.length} PASS`);
  process.exit(failed.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
