<h1>Verifikasi 2 langkah</h1>
<p>Masukkan 6 digit kode dari aplikasi authenticator di HP.</p>
<form method="post" action="/login/2fa" class="stack">
  <?= csrf_field() ?>
  <div class="field">
    <label for="code">Kode</label>
    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
  </div>
  <button class="btn btn-primary btn-block" type="submit">Verifikasi</button>
</form>
<form method="post" action="/logout">
  <?= csrf_field() ?>
  <button class="btn btn-quiet" type="submit">Batal, kembali ke login</button>
</form>
