<?php $invalid = $r['invalid_count'] ?? 0; ?>
<div class="page-head">
  <div>
    <p class="crumb"><a href="/leads">Leads</a></p>
    <h1>Hasil import</h1>
    <p class="muted small"><?= e($r['name']) ?></p>
  </div>
</div>

<section class="card form-narrow">
  <dl class="kv">
    <dt>Lead baru tersimpan</dt><dd class="num"><strong><?= number_format($r['inserted'], 0, ',', '.') ?></strong></dd>
    <dt>Dilewati, nomor sudah ada</dt><dd class="num"><?= number_format($r['existing'], 0, ',', '.') ?></dd>
    <dt>Dilewati, nomor dobel di file</dt><dd class="num"><?= number_format($r['dup_in_file'], 0, ',', '.') ?></dd>
    <dt>Dilewati, nomor tidak valid</dt><dd class="num"><?= number_format($invalid, 0, ',', '.') ?></dd>
    <?php if ($r['unknown_stage']): ?><dt>Stage tidak dikenal (masuk stage awal)</dt><dd class="num"><?= $r['unknown_stage'] ?></dd><?php endif; ?>
    <?php if ($r['bad_date']): ?><dt>Tanggal tidak terbaca (pakai waktu import)</dt><dd class="num"><?= $r['bad_date'] ?></dd><?php endif; ?>
  </dl>
  <?php if ($invalid): ?>
    <p class="small">Baris dengan nomor tidak valid: <?= e(implode(', ', $r['invalid'])) ?><?= !empty($r['invalid_more']) ? ', dan lainnya' : '' ?>.</p>
  <?php endif; ?>
  <?php if ($r['scientific']): ?>
    <p class="small warn-text">Sebagian nomor berformat 6,28E+12. Ubah format kolom nomor di Excel jadi Text, simpan ulang sebagai CSV, lalu import lagi. Nomor yang sudah masuk akan dilewati.</p>
  <?php endif; ?>
  <div class="btn-row">
    <a class="btn btn-primary" href="<?= e(url('/leads', ['source' => 'import'])) ?>">Lihat lead hasil import</a>
    <a class="btn" href="/leads/import">Import file lain</a>
  </div>
</section>
