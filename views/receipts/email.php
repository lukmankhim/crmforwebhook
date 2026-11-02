<?php
/** Email HTML kuitansi: tabel sederhana + style inline supaya aman di Gmail dan Mail iPhone. */
$a = $accent['accent'];
$soft = $accent['soft'];
$amount = (float) $payment['amount'];
$row = function (string $label, string $value): string {
    return '<tr><td style="padding:8px 0;border-bottom:1px solid #e5e7eb;color:#4b5563;font-size:14px;width:150px;vertical-align:top;">' . e($label)
        . '</td><td style="padding:8px 0;border-bottom:1px solid #e5e7eb;color:#111827;font-size:14px;vertical-align:top;">' . $value . '</td></tr>';
};
?>
<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kuitansi <?= e($payment['receipt_no']) ?></title></head>
<body style="margin:0;padding:0;background:#f3f4f6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">
  <tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-top:6px solid <?= e($a) ?>;border-radius:8px;font-family:Inter,Arial,Helvetica,sans-serif;color:#111827;">
      <tr><td style="padding:24px 24px 8px;">
        <p style="margin:0;font-size:18px;font-weight:700;"><?= e($business) ?></p>
        <?php if ($contact !== ''): ?><p style="margin:4px 0 0;font-size:13px;color:#4b5563;"><?= e($contact) ?></p><?php endif; ?>
      </td></tr>
      <tr><td style="padding:8px 24px 0;">
        <p style="margin:0;font-size:14px;color:#4b5563;">Halo <?= e($lead['name'] ?: 'Bapak/Ibu') ?>, terima kasih. Pembayaran berikut sudah kami terima.</p>
      </td></tr>
      <tr><td style="padding:16px 24px 0;">
        <p style="margin:0;font-size:13px;color:#4b5563;">Kuitansi <?= e($kind_label) ?></p>
        <p style="margin:2px 0 0;font-size:16px;font-weight:700;color:<?= e($a) ?>;"><?= e($payment['receipt_no']) ?></p>
      </td></tr>
      <tr><td style="padding:12px 24px 0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <?= $row('Tanggal bayar', e(fmt_date($payment['paid_on']))) ?>
          <?= $row('Diterima dari', e($lead['name'] ?: '-')) ?>
          <?= $row('Untuk pembayaran', e($payment['description'] ?: '-')) ?>
          <?= $row('Metode', e($payment['method'])) ?>
        </table>
      </td></tr>
      <tr><td style="padding:16px 24px 0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?= e($soft) ?>;border-radius:6px;">
          <tr><td style="padding:14px 16px;">
            <p style="margin:0;font-size:13px;color:#4b5563;">Jumlah</p>
            <p style="margin:2px 0 0;font-size:26px;font-weight:700;color:#111827;"><?= e(fmt_money($amount)) ?></p>
            <p style="margin:4px 0 0;font-size:13px;font-style:italic;color:#111827;">Terbilang: <?= e(ucfirst(terbilang((int) round($amount)))) ?></p>
          </td></tr>
        </table>
      </td></tr>
      <?php if ($summary['deal'] !== null): ?>
      <tr><td style="padding:12px 24px 0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <?= $row('Total sudah dibayar', e(fmt_money($summary['paid']))) ?>
          <?= $row('Sisa', e(fmt_money($summary['remaining']))) ?>
        </table>
      </td></tr>
      <?php endif; ?>
      <tr><td style="padding:20px 24px 0;">
        <a href="<?= e($url) ?>" style="display:inline-block;background:<?= e($a) ?>;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:12px 18px;border-radius:6px;">Lihat atau cetak kuitansi</a>
      </td></tr>
      <tr><td style="padding:20px 24px 24px;">
        <?php if ($footer !== ''): ?><p style="margin:0;font-size:12px;color:#4b5563;"><?= e($footer) ?></p><?php endif; ?>
        <?php if ($address !== ''): ?><p style="margin:4px 0 0;font-size:12px;color:#4b5563;"><?= e($address) ?></p><?php endif; ?>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
