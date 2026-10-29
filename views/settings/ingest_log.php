<?php $statusLabels = ['received' => 'Antre', 'retry' => 'Coba ulang', 'processed' => 'Diproses', 'failed' => 'Gagal', 'rejected' => 'Ditolak']; ?>
<div class="page-head">
  <div>
    <p class="crumb"><a href="/settings/ingest">Webhook &amp; API</a></p>
    <h1>Log masuk #<?= (int) $row['id'] ?></h1>
  </div>
  <span class="status status--<?= e($row['status']) ?>"><?= e($statusLabels[$row['status']]) ?></span>
</div>

<section class="card">
  <dl class="kv kv--wrap">
    <dt>Sumber</dt><dd><?= e($row['source']) ?></dd>
    <dt>Waktu masuk</dt><dd><?= e(fmt_dt($row['created_at'], 'd M Y H:i:s')) ?></dd>
    <dt>external_id</dt><dd class="breakall"><?= e($row['external_id'] ?? '-') ?></dd>
    <dt>Percobaan</dt><dd><?= (int) $row['attempts'] ?></dd>
    <?php if ($row['next_retry_at']): ?><dt>Coba lagi</dt><dd><?= e(fmt_dt($row['next_retry_at'], 'd M Y H:i:s')) ?></dd><?php endif; ?>
    <?php if ($row['processed_at']): ?><dt>Diproses</dt><dd><?= e(fmt_dt($row['processed_at'], 'd M Y H:i:s')) ?></dd><?php endif; ?>
    <?php if ($row['lead_id']): ?><dt>Lead</dt><dd><a href="/leads/<?= (int) $row['lead_id'] ?>">Buka lead</a></dd><?php endif; ?>
    <?php if ($row['error']): ?><dt>Error</dt><dd class="error-text"><?= e($row['error']) ?></dd><?php endif; ?>
    <dt>IP pengirim</dt><dd><?= e($row['ip'] ?? '-') ?></dd>
  </dl>
  <?php if ($row['status'] === 'failed' && $row['payload'] !== null): ?>
    <form method="post" action="/settings/ingest/log/<?= (int) $row['id'] ?>/retry">
      <?= csrf_field() ?>
      <button class="btn btn-primary" type="submit">Proses ulang</button>
    </form>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Payload mentah</h2>
  <?php if ($pretty === null): ?>
    <p class="muted">Payload sudah dihapus (disimpan maksimal 30 hari).</p>
  <?php else: ?>
    <pre class="code-block"><?= e($pretty) ?></pre>
  <?php endif; ?>
</section>
