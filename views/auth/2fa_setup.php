<h1>Aktifkan verifikasi 2 langkah</h1>
<p>Akun admin wajib memakai 2FA. Langkahnya:</p>
<?= render_partial('auth/_totp_steps', ['secret' => $secret, 'uri' => $uri]) ?>
<form method="post" action="<?= e($action) ?>" class="stack">
  <?= csrf_field() ?>
  <div class="field">
    <label for="code">Kode 6 digit dari aplikasi</label>
    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required>
  </div>
  <button class="btn btn-primary btn-block" type="submit">Aktifkan dan masuk</button>
</form>
<form method="post" action="/logout">
  <?= csrf_field() ?>
  <button class="btn btn-quiet" type="submit">Batal, kembali ke login</button>
</form>
