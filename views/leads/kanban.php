<?php $editable = can('leads.edit'); ?>
<div class="page-head">
  <h1>Leads <span class="count"><?= number_format($total, 0, ',', '.') ?></span></h1>
  <div class="btn-row">
    <?= render_partial('leads/_viewtoggle', ['filters' => $filters]) ?>
    <?php if (can('settings')): ?><a class="btn" href="/leads/import">Import CSV</a><?php endif; ?>
    <?php if ($editable): ?><a class="btn btn-primary" href="/leads/new">Tambah lead</a><?php endif; ?>
  </div>
</div>

<form class="filters" method="get" action="/leads" role="search">
  <input type="hidden" name="view" value="kanban">
  <div class="field">
    <label for="f-q">Cari nama atau nomor</label>
    <input id="f-q" name="q" type="search" value="<?= e($filters['q']) ?>">
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
    <?php if ($filtered): ?><a class="btn btn-quiet" href="/leads?view=kanban">Reset</a><?php endif; ?>
  </div>
</form>

<?php if ($total === 0): ?>
  <div class="empty">
    <p><strong><?= $filtered ? 'Tidak ada lead yang cocok dengan filter.' : 'Belum ada lead di tenant ini.' ?></strong></p>
    <?php if ($filtered): ?><p><a href="/leads?view=kanban">Hapus filter</a></p><?php endif; ?>
  </div>
<?php else: ?>
<p class="muted small">Tiap kolom menampilkan <?= KANBAN_PER_COLUMN ?> lead yang terakhir disentuh. Pindah ke Lost lewat halaman detail karena perlu alasan.</p>
<div class="kanban" tabindex="0" aria-label="Kanban stage, geser ke samping untuk kolom lain">
  <?php foreach ($stages as $key => $s): $n = $counts[$key] ?? 0; ?>
    <section class="kanban__col kanban__col--<?= e($s['kind']) ?>" aria-labelledby="col-<?= e($key) ?>">
      <h2 class="kanban__head" id="col-<?= e($key) ?>">
        <span><?= e($s['label']) ?></span>
        <a class="kanban__count num" href="<?= e(url('/leads', array_merge($filters, ['view' => null, 'stage' => $key]))) ?>" title="Lihat semua di daftar"><?= number_format($n, 0, ',', '.') ?></a>
      </h2>
      <?php if (!$columns[$key]): ?>
        <p class="kanban__empty">Kosong</p>
      <?php endif; ?>
      <?php foreach ($columns[$key] as $l): ?>
        <article class="kcard">
          <a class="rowlink" href="/leads/<?= (int) $l['id'] ?>"><?= e($l['name'] ?: 'Tanpa nama') ?></a>
          <p class="kcard__meta num"><?= e(mask_phone($l['phone'])) ?></p>
          <p class="kcard__meta"><?= e($l['assigned_name'] ?? 'Belum ada PIC') ?><?= $l['next_followup'] ? ' · FU ' . e(fmt_date($l['next_followup'])) : '' ?></p>
          <?php if ($l['quote_value'] !== null): ?><p class="kcard__meta num"><?= e(fmt_money($l['quote_value'])) ?></p><?php endif; ?>
          <?php if ($editable && $s['kind'] !== 'lost'): ?>
          <form method="post" action="/leads/<?= (int) $l['id'] ?>/stage" class="kcard__move">
            <?= csrf_field() ?>
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <label class="visually-hidden" for="mv-<?= (int) $l['id'] ?>">Pindah <?= e($l['name'] ?: 'lead') ?> ke stage</label>
            <select id="mv-<?= (int) $l['id'] ?>" name="stage">
              <?php foreach ($stages as $k2 => $s2): if ($s2['kind'] === 'lost') continue; ?>
                <option value="<?= e($k2) ?>" <?= $k2 === $key ? 'selected' : '' ?>><?= e($s2['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-quiet" type="submit">Pindah</button>
          </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if ($n > count($columns[$key])): ?>
        <a class="kanban__more" href="<?= e(url('/leads', array_merge($filters, ['view' => null, 'stage' => $key]))) ?>">Lihat <?= $n - count($columns[$key]) ?> lainnya di daftar</a>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
