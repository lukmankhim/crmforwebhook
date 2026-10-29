<?php
$periodText = $d['days'] . ' hari terakhir (' . fmt_date($d['start_local']->format('Y-m-d')) . ' sampai ' . fmt_date($d['today_local']->format('Y-m-d')) . ')';
$funnelStages = array_filter($d['stages'], fn($s) => $s['kind'] !== 'lost');
$lostStages = array_filter($d['stages'], fn($s) => $s['kind'] === 'lost');
$stageMax = max(1, ...array_map(fn($k) => $d['by_stage'][$k]['n'] ?? 0, array_keys($d['stages'])));
?>
<div class="page-head">
  <div>
    <h1>Ringkasan</h1>
    <p class="muted small"><?= e($periodText) ?></p>
  </div>
  <nav class="segmented" aria-label="Periode">
    <?php foreach (DASHBOARD_PERIODS as $p): ?>
      <a href="<?= e(url('/', ['days' => $p === 30 ? null : $p])) ?>" <?= $d['days'] === $p ? 'aria-current="page"' : '' ?>><?= $p ?> hari</a>
    <?php endforeach; ?>
  </nav>
</div>

<?php if ($d['total_leads'] === 0): ?>
  <div class="empty">
    <p><strong>Belum ada lead di tenant ini, jadi belum ada angka yang bisa diringkas.</strong></p>
    <p class="muted">Sambungkan webhook di Pengaturan, Webhook &amp; API, atau tambah lead manual.</p>
    <p class="btn-row btn-row--center">
      <?php if (can('leads.edit')): ?><a class="btn btn-primary" href="/leads/new">Tambah lead</a><?php endif; ?>
      <?php if (can('settings')): ?><a class="btn" href="/settings/ingest">Atur webhook</a><?php endif; ?>
    </p>
  </div>
<?php else: ?>

<section class="tiles" aria-label="Angka utama">
  <div class="tile tile--focus">
    <p class="tile__label">Lead masuk</p>
    <p class="tile__value num"><?= number_format($d['leads'], 0, ',', '.') ?></p>
    <p class="tile__note"><?= e(delta_text($d['leads'], $d['leads_prev'])) ?></p>
  </div>
  <div class="tile">
    <p class="tile__label">Closing</p>
    <p class="tile__value num"><?= number_format($d['deals'], 0, ',', '.') ?></p>
    <p class="tile__note"><?= e(delta_text($d['deals'], $d['deals_prev'])) ?></p>
  </div>
  <div class="tile">
    <p class="tile__label">Nilai closing</p>
    <p class="tile__value num"><?= $d['deals'] ? e(fmt_money($d['deal_value'])) : '-' ?></p>
    <p class="tile__note"><?= $d['deals_without_value'] ? e($d['deals_without_value'] . ' closing belum diisi nilainya') : 'Dari closing di periode ini' ?></p>
  </div>
  <div class="tile">
    <p class="tile__label">Lead jadi closing</p>
    <p class="tile__value num"><?= e(pct($d['cohort_deals'], $d['leads'])) ?></p>
    <p class="tile__note">Qualified <?= e(pct($d['cohort_qualified'], $d['leads'])) ?>. Dihitung dari lead yang masuk di periode ini, lead baru masih bisa berproses.</p>
  </div>
</section>
<p class="muted small note-line">Spend, CPL, dan data iklan tampil setelah integrasi Google Ads dan Meta tersambung (fase berikutnya).</p>

<section class="card">
  <h2>Lead masuk per hari</h2>
  <?php if ($d['leads'] === 0): ?>
    <p class="empty-inline">Tidak ada lead masuk di periode ini.</p>
  <?php else: ?>
    <?= render_partial('dashboard/_bars', ['series' => $d['series']]) ?>
  <?php endif; ?>
</section>

<div class="split">
  <section class="card">
    <h2>Posisi pipeline sekarang</h2>
    <p class="muted small">Semua lead, tidak terbatas periode.</p>
    <ul class="hbars">
      <?php foreach ([$funnelStages, $lostStages] as $group): foreach ($group as $key => $s): $n = $d['by_stage'][$key]['n'] ?? 0; ?>
        <li class="hbars__row <?= $s['kind'] === 'lost' ? 'hbars__row--lost' : '' ?>">
          <a class="hbars__label" href="<?= e(url('/leads', ['stage' => $key])) ?>"><?= e($s['label']) ?></a>
          <svg class="hbars__track" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><?php if ($n > 0): ?><rect class="hbars__bar" x="0" y="0" height="10" width="<?= max(1, round($n / $stageMax * 100, 1)) ?>"/><?php endif; ?></svg>
          <span class="hbars__value num"><?= number_format($n, 0, ',', '.') ?></span>
        </li>
      <?php endforeach; endforeach; ?>
    </ul>
  </section>

  <section class="card">
    <h2>Follow up jatuh tempo</h2>
    <?php if (!$d['followups']): ?>
      <p class="empty-inline">Tidak ada follow up yang jatuh tempo hari ini atau terlewat.</p>
    <?php else: ?>
      <ul class="fu-list">
        <?php foreach ($d['followups'] as $f): $late = $f['next_followup'] < $d['today_local']->format('Y-m-d'); ?>
          <li>
            <a class="rowlink" href="/leads/<?= (int) $f['id'] ?>"><?= e($f['name'] ?: mask_phone($f['phone'])) ?></a>
            <span class="<?= $late ? 'warn-text' : 'muted' ?> small"><?= $late ? 'Terlewat, ' : 'Hari ini, ' ?><?= e(fmt_date($f['next_followup'])) ?></span>
            <span class="muted small"><?= e($d['stages'][$f['stage']]['label'] ?? $f['stage']) ?> · <?= e($f['assigned_name'] ?? 'Belum ada PIC') ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <h2>Lead per sumber</h2>
  <p class="muted small">Lead yang masuk di periode ini dan sudah sampai mana.</p>
  <?php if (!$d['by_source']): ?>
    <p class="empty-inline">Tidak ada lead masuk di periode ini.</p>
  <?php else: ?>
  <table class="table table--cards">
    <thead><tr><th>Sumber</th><th>Lead</th><th>Qualified</th><th>Closing</th><th>Lead jadi closing</th><th>Nilai closing</th></tr></thead>
    <tbody>
      <?php foreach ($d['by_source'] as $s): ?>
        <tr>
          <td data-label="Sumber"><a href="<?= e(url('/leads', ['source' => $s['source']])) ?>"><?= e(source_labels()[$s['source']] ?? $s['source']) ?></a></td>
          <td data-label="Lead" class="num"><?= (int) $s['leads'] ?></td>
          <td data-label="Qualified" class="num"><?= (int) $s['qualified'] ?></td>
          <td data-label="Closing" class="num"><?= (int) $s['deals'] ?></td>
          <td data-label="Lead jadi closing" class="num"><?= e(pct((int) $s['deals'], (int) $s['leads'])) ?></td>
          <td data-label="Nilai closing" class="num"><?= (int) $s['deals'] ? e(fmt_money($s['value'])) : '-' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php endif; ?>
