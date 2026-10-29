<?php
// Motif identitas: jalur stage. Segmen terisi sampai posisi lead di funnel, lost ditandai terpisah.
$st = $stages[$stage] ?? null;
$funnel = array_values(array_filter($stages, fn($s) => $s['kind'] !== 'lost'));
$pos = 0;
foreach ($funnel as $i => $s) {
    if ($s['stage_key'] === $stage) {
        $pos = $i + 1;
    }
}
$kind = $st['kind'] ?? 'open';
?>
<span class="stage stage--<?= e($kind) ?>">
  <span class="stage__track" aria-hidden="true">
    <?php foreach ($funnel as $i => $s): ?><span class="<?= $i < $pos ? 'on' : '' ?>"></span><?php endforeach; ?>
  </span>
  <span class="stage__label"><?= e($st['label'] ?? $stage) ?></span>
</span>
