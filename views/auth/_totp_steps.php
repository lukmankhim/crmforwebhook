<ol class="steps">
  <li>Buka aplikasi authenticator (Google Authenticator, Authy, 1Password, atau sejenisnya).</li>
  <li>Pilih tambah akun, lalu <strong>masukkan kunci secara manual</strong>:
    <div class="copyrow">
      <code class="secret" id="totp-secret"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code>
      <button class="btn btn-quiet" type="button" data-copy="<?= e($secret) ?>">Salin kunci</button>
    </div>
    <p class="muted small">Jenis: berbasis waktu, 6 digit. Di HP yang sama, bisa juga buka tautan ini: <a href="<?= e($uri) ?>">tambahkan ke aplikasi</a>.</p>
  </li>
  <li>Ketik kode 6 digit yang muncul di aplikasi.</li>
</ol>
