<section class="card error-page">
  <p class="muted">Kode <?= (int) $status ?></p>
  <h1><?= e($title) ?></h1>
  <?php if ($message !== ''): ?><p><?= e($message) ?></p><?php endif; ?>
  <p><a class="btn" href="/">Kembali ke halaman utama</a></p>
</section>
