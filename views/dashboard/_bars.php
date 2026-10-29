<?php
// Kolom lead masuk per hari. Satu seri, jadi tanpa legenda: judul kartu sudah menamai datanya.
// Tooltip per kolom lewat <title>, tabel ada di bawah untuk keyboard dan pembaca layar.
$w = 960;
$h = 220;
$padL = 32;
$padB = 24;
$padT = 12;
$plotW = $w - $padL - 8;
$plotH = $h - $padB - $padT;
$n = count($series);
$max = max(1, ...array_column($series, 'n'));
$stepY = max(1, (int) ceil($max / 3));
$top = $stepY * 3;
$slot = $plotW / max(1, $n);
$barW = min(24, max(2, $slot - 2)); // gap minimal 2px antar kolom
$y = fn(float $v) => $padT + $plotH - ($v / $top) * $plotH;
$labelIdx = array_unique([0, intdiv($n - 1, 2), $n - 1]);
?>
<div class="chart-wrap" tabindex="0" aria-label="Grafik, geser ke samping di layar kecil">
<svg class="chart" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img" aria-label="Lead masuk per hari, <?= $n ?> hari terakhir, tertinggi <?= $max ?> lead">
  <?php for ($g = 0; $g <= 3; $g++): $gy = $y($g * $stepY); ?>
    <line class="chart__grid" x1="<?= $padL ?>" x2="<?= $w - 8 ?>" y1="<?= $gy ?>" y2="<?= $gy ?>"/>
    <text class="chart__axis" x="<?= $padL - 6 ?>" y="<?= $gy + 4 ?>" text-anchor="end"><?= $g * $stepY ?></text>
  <?php endfor; ?>
  <?php foreach ($series as $i => $p):
      $x = $padL + $i * $slot + ($slot - $barW) / 2;
      $label = id_month((new DateTimeImmutable($p['date']))->format('d M')) . ': ' . $p['n'] . ' lead'; ?>
    <g class="chart__col">
      <rect class="chart__hit" x="<?= round($padL + $i * $slot, 2) ?>" y="<?= $padT ?>" width="<?= round($slot, 2) ?>" height="<?= $plotH ?>"><title><?= e($label) ?></title></rect>
      <?php if ($p['n'] > 0):
          $by = $y($p['n']);
          $bh = $padT + $plotH - $by;
          $rad = min(4, $barW / 2, $bh); ?>
        <path class="chart__bar" d="M<?= round($x, 2) ?>,<?= $padT + $plotH ?> V<?= round($by + $rad, 2) ?> Q<?= round($x, 2) ?>,<?= round($by, 2) ?> <?= round($x + $rad, 2) ?>,<?= round($by, 2) ?> H<?= round($x + $barW - $rad, 2) ?> Q<?= round($x + $barW, 2) ?>,<?= round($by, 2) ?> <?= round($x + $barW, 2) ?>,<?= round($by + $rad, 2) ?> V<?= $padT + $plotH ?> Z"><title><?= e($label) ?></title></path>
      <?php endif; ?>
    </g>
  <?php endforeach; ?>
  <line class="chart__base" x1="<?= $padL ?>" x2="<?= $w - 8 ?>" y1="<?= $padT + $plotH ?>" y2="<?= $padT + $plotH ?>"/>
  <?php foreach ($labelIdx as $i): $p = $series[$i]; ?>
    <text class="chart__axis" x="<?= $i === 0 ? $padL : ($i === $n - 1 ? $w - 8 : round($padL + $i * $slot + $slot / 2, 2)) ?>" y="<?= $h - 6 ?>" text-anchor="<?= $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle') ?>"><?= e(id_month((new DateTimeImmutable($p['date']))->format('d M'))) ?></text>
  <?php endforeach; ?>
</svg>
</div>
<details class="chart-table">
  <summary>Lihat sebagai tabel</summary>
  <table class="table">
    <thead><tr><th>Tanggal</th><th>Lead masuk</th></tr></thead>
    <tbody>
      <?php foreach (array_reverse($series) as $p): ?>
        <tr><td><?= e(fmt_date($p['date'])) ?></td><td class="num"><?= $p['n'] ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</details>
