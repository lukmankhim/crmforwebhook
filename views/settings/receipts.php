<?php
$secretNote = function (string $key) use ($has_secret): string {
    return $has_secret[$key] ? 'Sudah tersimpan (terenkripsi). Kosongkan kalau tidak diganti.' : 'Belum diisi.';
};
?>
<h1>Pengaturan</h1>
<?= render_partial('settings/_tabs', ['tab' => $tab]) ?>

<form class="card stack form-narrow" method="post" action="/settings/receipts">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="receipt">
  <h2>Kuitansi</h2>
  <p class="muted small">Tampil di kepala dan kaki kuitansi. Kosongkan nama usaha untuk memakai nama tenant.</p>
  <div class="field"><label for="receipt_business_name">Nama usaha</label><input id="receipt_business_name" name="receipt_business_name" type="text" maxlength="150" value="<?= e($s['receipt_business_name']) ?>"></div>
  <div class="field"><label for="receipt_address">Alamat</label><input id="receipt_address" name="receipt_address" type="text" maxlength="255" value="<?= e($s['receipt_address']) ?>"></div>
  <div class="field"><label for="receipt_contact">Kontak</label><input id="receipt_contact" name="receipt_contact" type="text" maxlength="255" value="<?= e($s['receipt_contact']) ?>" placeholder="WA 08xx · email · situs"></div>
  <div class="field"><label for="receipt_footer">Kalimat kaki</label><input id="receipt_footer" name="receipt_footer" type="text" maxlength="255" value="<?= e($s['receipt_footer']) ?>"></div>
  <div class="grid-2">
    <div class="field">
      <label for="receipt_prefix">Awalan nomor</label>
      <input id="receipt_prefix" name="receipt_prefix" type="text" maxlength="10" value="<?= e($s['receipt_prefix']) ?>" aria-describedby="prefix-help">
      <p id="prefix-help" class="muted small">Contoh nomor: <?= e(($s['receipt_prefix'] ?: 'KW') . '/' . date('Y/m') . '/0001') ?></p>
    </div>
    <div class="field">
      <label for="receipt_accent">Warna aksen (hex)</label>
      <input id="receipt_accent" name="receipt_accent" type="text" maxlength="7" value="<?= e($s['receipt_accent']) ?>" placeholder="#357a4e" aria-describedby="accent-help">
      <p id="accent-help" class="muted small"><?= $s['receipt_accent'] !== '' && $accent['adjusted'] ? 'Kurang kontras, otomatis digelapkan jadi ' . e($accent['accent']) . '.' : 'Dipakai untuk garis atas, nomor, dan tombol.' ?></p>
    </div>
  </div>
  <div class="field">
    <label for="receipt_logo">Logo</label>
    <select id="receipt_logo" name="receipt_logo" aria-describedby="logo-help">
      <option value="">Tanpa logo (pakai nama usaha)</option>
      <?php foreach ($logos as $l): ?><option value="<?= e($l) ?>" <?= $s['receipt_logo'] === $l ? 'selected' : '' ?>><?= e(basename($l)) ?></option><?php endforeach; ?>
    </select>
    <p id="logo-help" class="muted small">File logo diunggah ke folder <code>public/brand/</code> di server.</p>
  </div>
  <div class="actions"><button class="btn btn-primary" type="submit">Simpan kuitansi</button></div>
</form>

<form class="card stack form-narrow" method="post" action="/settings/receipts">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="email">
  <h2>Email kuitansi (Brevo)</h2>
  <p class="small"><?= $email_ready ? '<span class="status status--sent">Siap kirim</span>' : '<span class="status status--queued">Belum lengkap</span> Kuitansi tetap tercatat, emailnya menunggu sampai pengaturan ini diisi.' ?></p>
  <div class="grid-2">
    <div class="field"><label for="mail_from_email">Email pengirim</label><input id="mail_from_email" name="mail_from_email" type="email" maxlength="190" value="<?= e($s['mail_from_email']) ?>" aria-describedby="from-help"><p id="from-help" class="muted small">Domainnya harus sudah terverifikasi di Brevo.</p></div>
    <div class="field"><label for="mail_from_name">Nama pengirim</label><input id="mail_from_name" name="mail_from_name" type="text" maxlength="100" value="<?= e($s['mail_from_name']) ?>"></div>
  </div>
  <div class="field"><label for="mail_reply_to">Balasan klien ke (opsional)</label><input id="mail_reply_to" name="mail_reply_to" type="email" maxlength="190" value="<?= e($s['mail_reply_to']) ?>"></div>
  <div class="field">
    <label for="brevo_api_key">API key Brevo</label>
    <input id="brevo_api_key" name="brevo_api_key" type="password" autocomplete="new-password" maxlength="300" aria-describedby="brevo-key-help">
    <p id="brevo-key-help" class="muted small"><?= e($secretNote('brevo_api_key')) ?> IP server ini harus diizinkan di Brevo (Security, Authorized IPs).</p>
    <?php if ($has_secret['brevo_api_key']): ?><label class="check"><input type="checkbox" name="clear_brevo_api_key" value="1"> Hapus API key</label><?php endif; ?>
  </div>
  <h3 class="subhead">Webhook status email</h3>
  <p class="small">Di Brevo, buat webhook transaksional ke alamat ini dengan autentikasi Token, lalu centang event Delivered, Opened, Hard bounce, Spam, Blocked.</p>
  <div class="copyrow">
    <code class="secret breakall"><?= e($webhook_url) ?></code>
    <button class="btn btn-quiet" type="button" data-copy="<?= e($webhook_url) ?>">Salin URL</button>
  </div>
  <div class="field">
    <label for="brevo_webhook_token">Token webhook</label>
    <input id="brevo_webhook_token" name="brevo_webhook_token" type="password" autocomplete="new-password" maxlength="300" aria-describedby="hook-help">
    <p id="hook-help" class="muted small"><?= e($secretNote('brevo_webhook_token')) ?> Isi dengan token yang sama seperti di Brevo.</p>
  </div>
  <div class="actions"><button class="btn btn-primary" type="submit">Simpan email</button></div>
</form>

<form class="card stack form-narrow" method="post" action="/settings/receipts">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="meta">
  <h2>Konversi offline ke Meta</h2>
  <p class="small">
    <?= $meta_ready ? '<span class="status status--sent">Aktif</span>' : '<span class="status status--waiting_config">Belum diisi</span>' ?>
    Lead dari form website dan setiap pembayaran dikirim sebagai event Lead dan Purchase.
    <?php if (!$meta_ready && $waiting > 0): ?><?= (int) $waiting ?> event menunggu pengaturan ini (event lebih dari 7 hari tidak lagi diterima Meta).<?php endif; ?>
  </p>
  <div class="grid-2">
    <div class="field"><label for="meta_dataset_id">ID dataset (Pixel)</label><input id="meta_dataset_id" name="meta_dataset_id" type="text" inputmode="numeric" maxlength="20" value="<?= e($s['meta_dataset_id']) ?>"></div>
    <div class="field"><label for="meta_test_event_code">Test event code</label><input id="meta_test_event_code" name="meta_test_event_code" type="text" maxlength="40" value="<?= e($s['meta_test_event_code']) ?>" aria-describedby="test-help"><p id="test-help" class="muted small">Hanya saat uji di Events Manager. Kosongkan setelah selesai.</p></div>
  </div>
  <div class="field">
    <label for="meta_access_token">Access token Conversions API</label>
    <input id="meta_access_token" name="meta_access_token" type="password" autocomplete="new-password" maxlength="300" aria-describedby="meta-token-help">
    <p id="meta-token-help" class="muted small"><?= e($secretNote('meta_access_token')) ?></p>
    <?php if ($has_secret['meta_access_token']): ?><label class="check"><input type="checkbox" name="clear_meta_access_token" value="1"> Hapus token</label><?php endif; ?>
  </div>
  <div class="actions"><button class="btn btn-primary" type="submit">Simpan Meta</button></div>
</form>

<form class="card stack form-narrow" method="post" action="/settings/receipts">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="google">
  <h2>Konversi offline ke Google Ads</h2>
  <p class="small">
    <?= $gads_ready ? '<span class="status status--sent">Aktif</span>' : '<span class="status status--waiting_config">Belum diisi</span>' ?>
    Setiap pembayaran 7 hari terakhir masuk ke feed CSV (nomor HP dan email di-hash). Google Ads menariknya sendiri lewat
    Tools, Data manager, sumber HTTPS (konversi offline).
  </p>
  <div class="field">
    <label for="gads_conversion_name">Nama konversi</label>
    <input id="gads_conversion_name" name="gads_conversion_name" type="text" maxlength="100" value="<?= e($s['gads_conversion_name']) ?>" aria-describedby="gads-name-help">
    <p id="gads-name-help" class="muted small">Sama persis dengan nama conversion action (impor, enhanced conversions for leads) di akun Google Ads.</p>
  </div>
  <?php if ($s['gads_feed_password'] !== ''): ?>
    <div class="field">
      <span class="label">Alamat feed</span>
      <code class="secret breakall"><?= e($gads_feed_url) ?></code>
      <button class="btn btn-quiet" type="button" data-copy="<?= e($gads_feed_url) ?>">Salin URL</button>
    </div>
    <div class="grid-2">
      <div class="field"><span class="label">Username</span><code class="secret"><?= e($gads_feed_user) ?></code></div>
      <div class="field">
        <span class="label">Password</span>
        <code class="secret breakall"><?= e($s['gads_feed_password']) ?></code>
        <button class="btn btn-quiet" type="button" data-copy="<?= e($s['gads_feed_password']) ?>">Salin password</button>
      </div>
    </div>
    <label class="check"><input type="checkbox" name="new_gads_feed_password" value="1"> Buat password baru (password lama langsung tidak berlaku)</label>
  <?php endif; ?>
  <div class="actions"><button class="btn btn-primary" type="submit">Simpan Google Ads</button></div>
</form>
