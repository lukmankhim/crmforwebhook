<?php
$n = fn($v) => number_format((float) $v, 0, ',', '.');
$periodText = fmt_date($cur['f']) . ' sampai ' . fmt_date($cur['t']) . ', dibanding ' . fmt_date($prev['f']) . ' sampai ' . fmt_date($prev['t']);
$notConnected = function (string $label) {
    return '<p class="empty-inline">' . e($label) . ' belum tersambung untuk tenant ini.'
        . (can('settings') ? ' <a href="/settings/integrations">Sambungkan di Pengaturan</a>.' : ' Minta admin menyambungkannya kalau dibutuhkan.') . '</p>';
};
$waiting = fn(array|null $i) => $i && !$i['last_success_at']
    ? '<p class="flash flash--info">Menunggu sync pertama. Data 90 hari ke belakang ditarik otomatis dalam beberapa menit.</p>' : '';
?>
<div class="page-head">
  <div>
    <h1>Sumber</h1>
    <p class="muted small"><?= e($periodText) ?></p>
  </div>
  <nav class="segmented" aria-label="Periode">
    <?php foreach (DASHBOARD_PERIODS as $p): ?>
      <a href="<?= e(url('/sources', ['days' => $p === 30 ? null : $p])) ?>" <?= $days === $p ? 'aria-current="page"' : '' ?>><?= $p ?> hari</a>
    <?php endforeach; ?>
  </nav>
</div>

<section class="card">
  <h2>Website (Google Analytics 4)</h2>
  <?php if (!$ga4): ?>
    <?= $notConnected('Google Analytics 4') ?>
  <?php else: ?>
    <?= $waiting($integrations['ga4'] ?? null) ?>
    <div class="tiles tiles--compact">
      <div class="tile"><p class="tile__label">Sesi</p><p class="tile__value num"><?= $n($ga4['cur']['sessions']) ?></p><p class="tile__note"><?= e(change_cell((float) $ga4['cur']['sessions'], (float) $ga4['prev']['sessions'])) ?> dari <?= $n($ga4['prev']['sessions']) ?></p></div>
      <div class="tile"><p class="tile__label">Key events (konversi GA4)</p><p class="tile__value num"><?= $n($ga4['cur']['conversions']) ?></p><p class="tile__note"><?= e(change_cell((float) $ga4['cur']['conversions'], (float) $ga4['prev']['conversions'])) ?> dari <?= $n($ga4['prev']['conversions']) ?></p></div>
    </div>
    <?php foreach (['by_source' => 'Source / medium', 'by_campaign' => 'Campaign', 'by_page' => 'Landing page'] as $k => $label): ?>
      <h3 class="subhead"><?= e($label) ?></h3>
      <?php if (!$ga4[$k]): ?>
        <p class="empty-inline">Belum ada data di periode ini.</p>
      <?php else: ?>
      <table class="table table--cards">
        <thead><tr><th><?= e($label) ?></th><th>Sesi</th><th>Periode lalu</th><th>Perubahan</th><th>Key events</th></tr></thead>
        <tbody>
          <?php foreach ($ga4[$k] as $r): ?>
            <tr>
              <td data-label="<?= e($label) ?>" class="breakall"><?= e($r['dim'] !== '' && $r['dim'] !== ' / ' ? $r['dim'] : '(tidak ada)') ?></td>
              <td data-label="Sesi" class="num"><?= $n($r['sessions']) ?></td>
              <td data-label="Periode lalu" class="num"><?= $n($r['sessions_prev']) ?></td>
              <td data-label="Perubahan" class="num"><?= e(change_cell((float) $r['sessions'], (float) $r['sessions_prev'])) ?></td>
              <td data-label="Key events" class="num"><?= $n($r['conversions']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Pencarian Google (Search Console)</h2>
  <?php if (!$gsc): ?>
    <?= $notConnected('Search Console') ?>
  <?php else: ?>
    <?= $waiting($integrations['gsc'] ?? null) ?>
    <p class="muted small">Data Search Console tertunda sekitar 2 sampai 3 hari, jadi hari-hari terakhir periode biasanya masih kosong.</p>
    <div class="tiles tiles--compact">
      <div class="tile"><p class="tile__label">Klik</p><p class="tile__value num"><?= $n($gsc['cur']['clicks']) ?></p><p class="tile__note"><?= e(change_cell((float) $gsc['cur']['clicks'], (float) $gsc['prev']['clicks'])) ?> dari <?= $n($gsc['prev']['clicks']) ?></p></div>
      <div class="tile"><p class="tile__label">Impresi</p><p class="tile__value num"><?= $n($gsc['cur']['impressions']) ?></p><p class="tile__note"><?= e(change_cell((float) $gsc['cur']['impressions'], (float) $gsc['prev']['impressions'])) ?> dari <?= $n($gsc['prev']['impressions']) ?></p></div>
      <div class="tile"><p class="tile__label">CTR</p><p class="tile__value num"><?= e(pct((int) $gsc['cur']['clicks'], (int) $gsc['cur']['impressions'])) ?></p><p class="tile__note">Periode lalu <?= e(pct((int) $gsc['prev']['clicks'], (int) $gsc['prev']['impressions'])) ?></p></div>
      <div class="tile"><p class="tile__label">Posisi rata-rata</p><p class="tile__value num"><?= (float) $gsc['cur']['position'] > 0 ? number_format((float) $gsc['cur']['position'], 1, ',', '.') : '-' ?></p><p class="tile__note">Periode lalu <?= (float) $gsc['prev']['position'] > 0 ? number_format((float) $gsc['prev']['position'], 1, ',', '.') : '-' ?>. Makin kecil makin baik.</p></div>
    </div>
    <?php foreach (['by_page' => 'Halaman', 'by_query' => 'Kata kunci'] as $k => $label): ?>
      <h3 class="subhead"><?= e($label) ?></h3>
      <?php if ($k === 'by_query'): ?><p class="muted small">Google menyembunyikan kata kunci yang jarang dicari, jadi total klik di tabel ini lebih kecil dari total di atas.</p><?php endif; ?>
      <?php if (!$gsc[$k]): ?>
        <p class="empty-inline">Belum ada data di periode ini.</p>
      <?php else: ?>
      <table class="table table--cards">
        <thead><tr><th><?= e($label) ?></th><th>Klik</th><th>Periode lalu</th><th>Perubahan</th><th>Impresi</th><th>Posisi</th></tr></thead>
        <tbody>
          <?php foreach ($gsc[$k] as $r): ?>
            <tr>
              <td data-label="<?= e($label) ?>" class="breakall"><?= e($r['dim']) ?></td>
              <td data-label="Klik" class="num"><?= $n($r['clicks']) ?></td>
              <td data-label="Periode lalu" class="num"><?= $n($r['clicks_prev']) ?></td>
              <td data-label="Perubahan" class="num"><?= e(change_cell((float) $r['clicks'], (float) $r['clicks_prev'])) ?></td>
              <td data-label="Impresi" class="num"><?= $n($r['impressions']) ?></td>
              <td data-label="Posisi" class="num"><?= $r['position'] !== null ? number_format((float) $r['position'], 1, ',', '.') : '-' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
