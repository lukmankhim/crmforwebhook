<div class="page-head">
  <h1>Leads <span class="count"><?= number_format($total, 0, ',', '.') ?></span></h1>
  <div class="btn-row">
    <?= render_partial('leads/_viewtoggle', ['filters' => $filters]) ?>
    <?php if (can('settings')): ?><a class="btn" href="/leads/import">Import CSV</a><?php endif; ?>
    <?php if (can('leads.edit')): ?><a class="btn btn-primary" href="/leads/new">Tambah lead</a><?php endif; ?>
  </div>
</div>

<form class="filters" method="get" action="/leads" role="search">
  <div class="field">
    <label for="f-q">Cari nama atau nomor</label>
    <input id="f-q" name="q" type="search" value="<?= e($filters['q']) ?>">
  </div>
  <div class="field">
    <label for="f-stage">Stage</label>
    <select id="f-stage" name="stage">
      <option value="">Semua</option>
      <?php foreach ($stages as $key => $s): ?>
        <option value="<?= e($key) ?>" <?= $filters['stage'] === $key ? 'selected' : '' ?>><?= e($s['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-source">Sumber</label>
    <select id="f-source" name="source">
      <option value="">Semua</option>
      <?php foreach (source_labels() as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= $filters['source'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <label class="check"><input type="checkbox" name="mine" value="1" <?= $filters['mine'] ? 'checked' : '' ?>> Milik saya</label>
  <div class="filters__actions">
    <button class="btn" type="submit">Terapkan</button>
    <?php if ($filtered): ?><a class="btn btn-quiet" href="/leads">Reset</a><?php endif; ?>
  </div>
</form>

<?php if (!$leads): ?>
  <div class="empty">
    <?php if ($filtered): ?>
      <p><strong>Tidak ada lead yang cocok dengan filter.</strong></p>
      <p><a href="/leads">Hapus filter</a></p>
    <?php else: ?>
      <p><strong>Belum ada lead di tenant ini.</strong></p>
      <p class="muted">Lead masuk dari webhook (atur di Pengaturan, Webhook &amp; API), import CSV, atau ditambah manual oleh sales.</p>
      <?php if (can('leads.edit')): ?><p><a class="btn btn-primary" href="/leads/new">Tambah lead pertama</a></p><?php endif; ?>
    <?php endif; ?>
  </div>
<?php else: ?>
  <table class="table table--cards">
    <thead>
      <tr><th>Nama</th><th>Nomor</th><th>Stage</th><th>Sumber</th><th>PIC</th><th>Follow up</th><th>Masuk</th></tr>
    </thead>
    <tbody>
      <?php foreach ($leads as $l): ?>
        <tr>
          <td data-label="Nama"><a class="rowlink" href="/leads/<?= (int) $l['id'] ?>"><?= e($l['name'] ?: 'Tanpa nama') ?></a></td>
          <td data-label="Nomor" class="num"><?= e(mask_phone($l['phone'])) ?></td>
          <td data-label="Stage"><?= render_partial('leads/_stage', ['stages' => $stages, 'stage' => $l['stage']]) ?></td>
          <td data-label="Sumber"><?= e(source_labels()[$l['source']] ?? $l['source']) ?></td>
          <td data-label="PIC"><?= e($l['assigned_name'] ?? '') ?: '<span class="muted">Belum ada</span>' ?></td>
          <td data-label="Follow up"><?= e(fmt_date($l['next_followup'])) ?></td>
          <td data-label="Masuk" class="nowrap"><?= e(fmt_dt($l['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Halaman">
      <?php if ($page > 1): ?><a class="btn btn-quiet" href="<?= e(url('/leads', $filters + ['page' => $page - 1])) ?>">Sebelumnya</a><?php endif; ?>
      <span>Halaman <?= $page ?> dari <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn btn-quiet" href="<?= e(url('/leads', $filters + ['page' => $page + 1])) ?>">Berikutnya</a><?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>
