<h1>Akun saya</h1>

<section class="card form-narrow">
  <dl class="kv">
    <dt>Nama</dt><dd><?= e($user['name']) ?></dd>
    <dt>Username</dt><dd><?= e($user['username']) ?></dd>
    <dt>Email</dt><dd><?= e($user['email'] ?? '-') ?></dd>
    <?php if ($user['is_super']): ?><dt>Peran</dt><dd>Superadmin</dd><?php endif; ?>
  </dl>
</section>

<section class="card form-narrow">
  <h2>Ganti password</h2>
  <form method="post" action="/account/password" class="stack">
    <?= csrf_field() ?>
    <div class="field"><label for="current_password">Password lama</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
    <div class="field"><label for="new_password">Password baru (minimal 10 karakter)</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="10" required></div>
    <div class="field"><label for="confirm_password">Ulangi password baru</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="10" required></div>
    <div class="actions"><button class="btn btn-primary" type="submit">Ganti password</button></div>
  </form>
</section>

<section class="card form-narrow">
  <h2>Verifikasi 2 langkah</h2>
  <?php if ($user['totp_secret'] !== null): ?>
    <p>Aktif.<?= $required_2fa ? ' Wajib untuk akun admin, tidak bisa dimatikan. Kalau HP hilang, minta admin lain atau superadmin mereset 2FA.' : '' ?></p>
    <?php if (!$required_2fa): ?>
    <form method="post" action="/account/2fa">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="disable">
      <button class="btn btn-danger" type="submit" data-confirm="Matikan verifikasi 2 langkah?">Matikan 2FA</button>
    </form>
    <?php endif; ?>
  <?php else: ?>
    <p>Belum aktif. Disarankan untuk semua akun.</p>
    <?= render_partial('auth/_totp_steps', ['secret' => $setup_secret, 'uri' => $setup_uri]) ?>
    <form method="post" action="/account/2fa" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="enable">
      <div class="field"><label for="code">Kode 6 digit</label><input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required></div>
      <div class="actions"><button class="btn btn-primary" type="submit">Aktifkan 2FA</button></div>
    </form>
  <?php endif; ?>
</section>
