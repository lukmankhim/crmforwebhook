<?php
/** @var array $payment @var array $lead @var array $summary */
$void = $payment['voided_at'] !== null;
$amount = (float) $payment['amount'];
$tenant = current_tenant();
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> · <?= e($business) ?></title>
  <link rel="stylesheet" href="/assets/receipt.css">
  <link rel="stylesheet" href="/kuitansi-tema/<?= e($tenant['slug']) ?>.css">
  <script src="/assets/app.js" defer></script>
</head>
<body class="receipt-page">
<main class="receipt" aria-labelledby="receipt-title">
  <header class="receipt__head">
    <div class="receipt__brand">
      <?php if ($logo): ?><img class="receipt__logo" src="<?= e($logo) ?>" alt="<?= e($business) ?>"><?php else: ?><p class="receipt__business"><?= e($business) ?></p><?php endif; ?>
      <?php if ($address !== ''): ?><p class="receipt__small"><?= e($address) ?></p><?php endif; ?>
      <?php if ($contact !== ''): ?><p class="receipt__small"><?= e($contact) ?></p><?php endif; ?>
    </div>
    <div class="receipt__meta">
      <h1 id="receipt-title">Kuitansi</h1>
      <p class="receipt__no"><?= e($payment['receipt_no']) ?></p>
      <p class="receipt__small">Tanggal bayar <?= e(fmt_date($payment['paid_on'])) ?></p>
    </div>
  </header>

  <?php if ($void): ?>
  <p class="receipt__void" role="status">Kuitansi ini dibatalkan pada <?= e(fmt_dt($payment['voided_at'], 'd M Y')) ?> dan tidak berlaku.</p>
  <?php endif; ?>

  <dl class="receipt__rows">
    <div><dt>Diterima dari</dt><dd><?= e($lead['name'] ?: '-') ?><?php if ($lead['email']): ?><span class="receipt__small"><?= e($lead['email']) ?></span><?php endif; ?></dd></div>
    <div><dt>Untuk pembayaran</dt><dd><?= e($payment['description'] ?: '-') ?></dd></div>
    <div><dt>Jenis</dt><dd><span class="receipt__kind"><?= e($kind_label) ?></span></dd></div>
    <div><dt>Metode</dt><dd><?= e($payment['method']) ?></dd></div>
    <?php if ($payment['note']): ?><div><dt>Catatan</dt><dd><?= e($payment['note']) ?></dd></div><?php endif; ?>
  </dl>

  <section class="receipt__amount" aria-label="Jumlah dibayar">
    <p class="receipt__label">Jumlah</p>
    <p class="receipt__total<?= $void ? ' receipt__total--void' : '' ?>"><?= e(fmt_money($amount)) ?></p>
    <p class="receipt__words">Terbilang: <?= e(ucfirst(terbilang((int) round($amount)))) ?></p>
  </section>

  <?php if ($summary['deal'] !== null && !$void): ?>
  <dl class="receipt__summary">
    <div><dt>Nilai kesepakatan</dt><dd><?= e(fmt_money($summary['deal'])) ?></dd></div>
    <div><dt>Total sudah dibayar</dt><dd><?= e(fmt_money($summary['paid'])) ?></dd></div>
    <div><dt>Sisa</dt><dd><?= e(fmt_money($summary['remaining'])) ?></dd></div>
  </dl>
  <?php endif; ?>

  <footer class="receipt__foot">
    <?php if ($footer !== ''): ?><p><?= e($footer) ?></p><?php endif; ?>
    <p class="receipt__small">Diterbitkan oleh <?= e($business) ?>.</p>
  </footer>

  <p class="receipt__actions"><button type="button" class="receipt__btn" data-print>Cetak atau simpan PDF</button></p>
</main>
</body>
</html>
