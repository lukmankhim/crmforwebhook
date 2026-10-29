<div class="page-head">
  <div>
    <p class="crumb"><a href="/leads">Leads</a></p>
    <h1>Import CSV</h1>
  </div>
</div>

<form class="card stack form-narrow" method="post" action="/leads/import" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="field">
    <label for="file">File CSV (maksimal 2MB, <?= number_format(IMPORT_MAX_ROWS, 0, ',', '.') ?> baris)</label>
    <input id="file" name="file" type="file" accept=".csv,.txt,text/csv" required>
  </div>
  <div class="actions"><button class="btn btn-primary" type="submit">Upload dan pasangkan kolom</button></div>
</form>

<section class="card form-narrow">
  <h2>Yang terjadi saat import</h2>
  <ul class="plain-list">
    <li>Setelah upload, kamu memilih kolom mana yang berisi nomor HP, nama, stage, dan lainnya. Belum ada data yang disimpan sebelum tombol proses ditekan.</li>
    <li>Nomor dinormalisasi ke format 62. Baris dengan nomor tidak valid dilewati dan nomor barisnya ditampilkan.</li>
    <li>Nomor yang sudah ada di tenant ini dilewati, data lama tidak ditimpa.</li>
    <li>Sumber lead dicatat sebagai Import. Stage yang tidak dikenali masuk ke stage awal.</li>
    <li>Pemisah koma atau titik koma (format Excel Indonesia) dikenali otomatis.</li>
  </ul>
  <p class="muted small">Kalau nomor di Excel tampil seperti 6,28E+12, ubah format kolomnya jadi Text sebelum disimpan sebagai CSV.</p>
</section>
