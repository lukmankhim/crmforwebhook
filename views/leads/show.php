<?php
$editable = can('leads.edit');
$attr = [
    'src_tag' => 'Tag / campaign', 'ctwa_clid' => 'ctwa_clid', 'ad_id' => 'Ad ID', 'fbclid' => 'fbclid',
    'gclid' => 'gclid', 'gbraid' => 'gbraid', 'wbraid' => 'wbraid', 'ttclid' => 'ttclid',
    'landing_url' => 'Landing URL', 'referrer' => 'Referrer', 'user_agent' => 'User agent',
];
$attrFilled = array_filter($attr, fn($k) => $lead[$k] !== null && $lead[$k] !== '', ARRAY_FILTER_USE_KEY);
$typeLabels = ['created' => 'Lead masuk', 'ingest_repeat' => 'Masuk lagi', 'stage' => 'Stage', 'update' => 'Data diubah', 'note' => 'Catatan', 'payment' => 'Pembayaran', 'payment_void' => 'Kuitansi batal', 'email' => 'Email'];
$attr = ['fbp' => '_fbp', 'fbc' => '_fbc', 'lead_event_id' => 'Event ID form'] + $attr;
?>
<div class="page-head">
  <div>
    <p class="crumb"><a href="/leads">Leads</a></p>
    <h1><?= e($lead['name'] ?: 'Tanpa nama') ?></h1>
    <p class="lead-meta">
      <span class="num"><?= e($lead['phone']) ?></span>
      <a href="https://wa.me/<?= e($lead['phone']) ?>" target="_blank" rel="noopener noreferrer">Buka chat WhatsApp</a>
    </p>
  </div>
  <?= render_partial('leads/_stage', ['stages' => $stages, 'stage' => $lead['stage']]) ?>
</div>

<div class="detail-grid">
  <div class="stack">
    <?php if ($editable): ?>
    <section class="card">
      <h2>Pindah stage</h2>
      <form method="post" action="/leads/<?= (int) $lead['id'] ?>/stage" class="inline-form" data-stage-form>
        <?= csrf_field() ?>
        <div class="field">
          <label for="stage">Stage</label>
          <select id="stage" name="stage">
            <?php foreach ($stages as $key => $s): ?>
              <option value="<?= e($key) ?>" data-kind="<?= e($s['kind']) ?>" <?= $lead['stage'] === $key ? 'selected' : '' ?>><?= e($s['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" data-lost-reason>
          <label for="lost_reason_stage">Alasan lost</label>
          <input id="lost_reason_stage" name="lost_reason" type="text" maxlength="255">
        </div>
        <button class="btn btn-primary" type="submit">Simpan stage</button>
      </form>
    </section>
    <?php endif; ?>

    <section class="card">
      <h2>Data lead</h2>
      <?php if ($editable): ?>
      <form method="post" action="/leads/<?= (int) $lead['id'] ?>" class="stack">
        <?= csrf_field() ?>
        <div class="grid-2">
          <div class="field"><label for="name">Nama</label><input id="name" name="name" type="text" value="<?= e($lead['name']) ?>"></div>
          <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" maxlength="190" autocomplete="off" value="<?= e($lead['email']) ?>" aria-describedby="email-help">
            <?php if ($lead['email_bounced_at'] !== null): ?><p id="email-help" class="small error-text">Email ke alamat ini mental (<?= e(fmt_dt($lead['email_bounced_at'])) ?>). Minta alamat yang benar.</p><?php endif; ?>
          </div>
          <div class="field">
            <label for="assigned_to">PIC</label>
            <select id="assigned_to" name="assigned_to">
              <option value="">Belum ada</option>
              <?php foreach ($members as $m): ?>
                <option value="<?= (int) $m['id'] ?>" <?= (int) $lead['assigned_to'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label for="product">Produk</label><input id="product" name="product" type="text" value="<?= e($lead['product']) ?>"></div>
          <div class="field"><label for="city">Kota</label><input id="city" name="city" type="text" value="<?= e($lead['city']) ?>"></div>
          <div class="field"><label for="quote_value">Nilai quotation (Rp)</label><input id="quote_value" name="quote_value" type="text" inputmode="numeric" value="<?= $lead['quote_value'] !== null ? (int) $lead['quote_value'] : '' ?>"></div>
          <div class="field"><label for="deal_value">Nilai deal (Rp)</label><input id="deal_value" name="deal_value" type="text" inputmode="numeric" value="<?= $lead['deal_value'] !== null ? (int) $lead['deal_value'] : '' ?>"></div>
          <div class="field"><label for="next_followup">Follow up berikutnya</label><input id="next_followup" name="next_followup" type="date" value="<?= e($lead['next_followup']) ?>"></div>
          <div class="field"><label for="lost_reason">Alasan lost</label><input id="lost_reason" name="lost_reason" type="text" maxlength="255" value="<?= e($lead['lost_reason']) ?>"></div>
        </div>
        <label class="check"><input type="checkbox" name="receipt_optin" value="1" <?= $lead['receipt_optin_at'] !== null ? 'checked' : '' ?>> Klien setuju kuitansi dikirim ke email</label>
        <div class="actions"><button class="btn btn-primary" type="submit">Simpan perubahan</button></div>
      </form>
      <?php else: ?>
      <dl class="kv">
        <dt>Email</dt><dd><?= e($lead['email']) ?: '-' ?></dd>
        <dt>Produk</dt><dd><?= e($lead['product']) ?: '-' ?></dd>
        <dt>Kota</dt><dd><?= e($lead['city']) ?: '-' ?></dd>
        <dt>Nilai quotation</dt><dd><?= e(fmt_money($lead['quote_value'])) ?: '-' ?></dd>
        <dt>Nilai deal</dt><dd><?= e(fmt_money($lead['deal_value'])) ?: '-' ?></dd>
        <dt>Follow up</dt><dd><?= e(fmt_date($lead['next_followup'])) ?: '-' ?></dd>
        <dt>Alasan lost</dt><dd><?= e($lead['lost_reason']) ?: '-' ?></dd>
      </dl>
      <?php endif; ?>
    </section>

    <section class="card" id="pembayaran">
      <h2>Pembayaran</h2>
      <dl class="kv">
        <dt>Nilai deal</dt><dd class="num"><?= $pay_summary['deal'] !== null ? e(fmt_money($pay_summary['deal'])) : '<span class="muted">Belum diisi</span>' ?></dd>
        <dt>Sudah dibayar</dt><dd class="num"><?= e(fmt_money($pay_summary['paid'])) ?></dd>
        <?php if ($pay_summary['remaining'] !== null): ?><dt>Sisa</dt><dd class="num"><?= e(fmt_money($pay_summary['remaining'])) ?></dd><?php endif; ?>
        <dt>Kuitansi lewat email</dt>
        <dd>
          <?php if (lead_wants_receipt($lead)): ?>Ya, ke <?= e($lead['email']) ?>
          <?php elseif ($lead['email'] === null || $lead['email'] === ''): ?><span class="muted">Tidak, belum ada email</span>
          <?php elseif ($lead['email_bounced_at'] !== null): ?><span class="warn-text">Tidak, email mental</span>
          <?php else: ?><span class="muted">Tidak, klien belum setuju</span><?php endif; ?>
          <?php if (lead_wants_receipt($lead) && !$email_ready): ?><span class="small warn-text error-text">Pengaturan email tenant belum lengkap, email akan menunggu.</span><?php endif; ?>
        </dd>
      </dl>

      <?php if ($editable): ?>
      <form method="post" action="/leads/<?= (int) $lead['id'] ?>/payments" class="stack">
        <?= csrf_field() ?>
        <h3 class="subhead">Catat pembayaran</h3>
        <div class="grid-2">
          <div class="field">
            <label for="pay_kind">Jenis</label>
            <select id="pay_kind" name="kind" required>
              <?php foreach (PAYMENT_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= old('kind') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label for="pay_amount">Nominal (Rp)</label><input id="pay_amount" name="amount" type="text" inputmode="numeric" required value="<?= e(old('amount') ?: '') ?>"></div>
          <div class="field"><label for="pay_date">Tanggal bayar</label><input id="pay_date" name="paid_on" type="date" required value="<?= e(old('paid_on') ?: (new DateTimeImmutable('now', tenant_tz()))->format('Y-m-d')) ?>"></div>
          <div class="field">
            <label for="pay_method">Metode</label>
            <select id="pay_method" name="pay_method" required>
              <?php foreach (PAYMENT_METHODS as $m): ?><option value="<?= e($m) ?>" <?= old('method') === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field">
          <label for="pay_desc">Untuk pembayaran</label>
          <input id="pay_desc" name="description" type="text" maxlength="255" value="<?= e(old('description') ?: trim(($lead['product'] ?? '') . ($lead['city'] ? ', ' . $lead['city'] : ''), ', ')) ?>" aria-describedby="pay-desc-help">
          <p id="pay-desc-help" class="muted small">Tampil di kuitansi, misalnya jenis acara, tanggal, dan lokasi.</p>
        </div>
        <div class="field"><label for="pay_note">Catatan (opsional)</label><input id="pay_note" name="note" type="text" maxlength="255" value="<?= e(old('note')) ?>"></div>
        <div class="actions"><button class="btn btn-primary" type="submit">Simpan pembayaran</button></div>
      </form>
      <?php endif; ?>

      <?php if (!$payments): ?>
        <p class="muted">Belum ada pembayaran.</p>
      <?php else: ?>
      <table class="table table--cards payments">
        <thead><tr><th>Kuitansi</th><th>Tanggal</th><th>Jenis</th><th class="num">Nominal</th><th>Email</th><th><span class="visually-hidden">Aksi</span></th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): $void = $p['voided_at'] !== null; ?>
          <tr>
            <td data-label="Kuitansi"><a href="<?= e(payment_receipt_url($p)) ?>" target="_blank" rel="noopener"><?= e($p['receipt_no']) ?></a><?php if ($void): ?> <span class="tag">Batal</span><?php endif; ?></td>
            <td data-label="Tanggal"><?= e(fmt_date($p['paid_on'])) ?></td>
            <td data-label="Jenis"><?= e(PAYMENT_KINDS[$p['kind']] ?? $p['kind']) ?> · <?= e($p['method']) ?></td>
            <td data-label="Nominal" class="num"><?= $void ? '<s>' . e(fmt_money($p['amount'])) . '</s>' : e(fmt_money($p['amount'])) ?></td>
            <td data-label="Email"><?= $p['email_status'] ? '<span class="status status--' . e($p['email_status']) . '">' . e(EMAIL_STATUS_LABELS[$p['email_status']] ?? $p['email_status']) . '</span>' : '<span class="muted">Tidak dikirim</span>' ?></td>
            <td data-label="Aksi">
              <?php if (!$void && $editable): ?>
              <div class="btn-row">
                <?php if ($lead['email']): ?>
                <form method="post" action="/payments/<?= (int) $p['id'] ?>/resend"><?= csrf_field() ?><button class="btn btn-quiet" type="submit" data-confirm="Kirim kuitansi <?= e($p['receipt_no']) ?> ke <?= e($lead['email']) ?>?"><?= $p['email_status'] ? 'Kirim ulang' : 'Kirim email' ?></button></form>
                <?php endif; ?>
                <?php if (can('settings')): ?>
                <details class="void-box">
                  <summary class="btn btn-quiet">Batalkan</summary>
                  <form method="post" action="/payments/<?= (int) $p['id'] ?>/void" class="stack">
                    <?= csrf_field() ?>
                    <div class="field"><label for="void_<?= (int) $p['id'] ?>">Alasan batal</label><input id="void_<?= (int) $p['id'] ?>" name="void_reason" type="text" maxlength="255" required></div>
                    <button class="btn btn-danger" type="submit">Batalkan kuitansi</button>
                  </form>
                </details>
                <?php endif; ?>
              </div>
              <?php elseif ($void): ?><span class="small muted"><?= e($p['void_reason']) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Aktivitas</h2>
      <?php if ($editable): ?>
      <form method="post" action="/leads/<?= (int) $lead['id'] ?>/note" class="stack">
        <?= csrf_field() ?>
        <div class="field">
          <label for="note">Tambah catatan</label>
          <textarea id="note" name="note" rows="3" maxlength="2000" required></textarea>
        </div>
        <div class="actions"><button class="btn" type="submit">Simpan catatan</button></div>
      </form>
      <?php endif; ?>
      <?php if (!$activity): ?>
        <p class="muted">Belum ada aktivitas.</p>
      <?php else: ?>
      <ol class="timeline">
        <?php foreach ($activity as $a): ?>
          <li>
            <p class="timeline__meta">
              <strong><?= e($typeLabels[$a['type']] ?? $a['type']) ?></strong>
              <span class="muted"><?= e(fmt_dt($a['created_at'])) ?> · <?= e($a['user_name'] ?? 'Sistem') ?></span>
            </p>
            <?php if ($a['detail'] !== null && $a['detail'] !== ''): ?><p class="timeline__body"><?= nl2br(e($a['detail'])) ?></p><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </section>
  </div>

  <aside class="stack">
    <section class="card">
      <h2>Ringkasan</h2>
      <dl class="kv">
        <dt>Sumber</dt><dd><?= e(source_labels()[$lead['source']] ?? $lead['source']) ?></dd>
        <dt>Masuk</dt><dd><?= e(fmt_dt($lead['created_at'])) ?></dd>
        <dt>Stage sejak</dt><dd><?= e(fmt_dt($lead['stage_at'])) ?: '-' ?></dd>
        <dt>Qualified</dt><dd><?= e(fmt_dt($lead['qualified_at'])) ?: '-' ?></dd>
        <dt>Closing</dt><dd><?= e(fmt_dt($lead['deal_at'])) ?: '-' ?></dd>
        <dt>Terakhir disentuh</dt><dd><?= e(fmt_dt($lead['touched_at'])) ?: '-' ?></dd>
      </dl>
    </section>
    <section class="card">
      <h2>Atribusi</h2>
      <?php if (!$attrFilled): ?>
        <p class="muted">Tidak ada data klik atau campaign.</p>
      <?php else: ?>
      <dl class="kv kv--wrap">
        <?php foreach ($attrFilled as $k => $label): ?>
          <dt><?= e($label) ?></dt><dd class="breakall"><?= e($lead[$k]) ?></dd>
        <?php endforeach; ?>
      </dl>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2>Konversi iklan</h2>
      <?php if (!$conversions): ?>
        <p class="muted">Belum ada event konversi untuk lead ini.</p>
      <?php else: ?>
      <ul class="plain-list">
        <?php foreach ($conversions as $c): ?>
          <li>
            <strong><?= e($c['event_name']) ?></strong><?= $c['value'] !== null ? ' ' . e(fmt_money($c['value'])) : '' ?>
            · <span class="status status--<?= e($c['status']) ?>"><?= e(CONVERSION_LABELS[$c['status']] ?? $c['status']) ?></span>
            <br><span class="small muted"><?= e(fmt_dt($c['event_time'])) ?><?= $c['response'] ? ' · ' . e($c['response']) : '' ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
  </aside>
</div>
