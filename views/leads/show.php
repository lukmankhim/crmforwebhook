<?php
$editable = can('leads.edit');
$attr = [
    'src_tag' => 'Tag / campaign', 'ctwa_clid' => 'ctwa_clid', 'ad_id' => 'Ad ID', 'fbclid' => 'fbclid',
    'gclid' => 'gclid', 'gbraid' => 'gbraid', 'wbraid' => 'wbraid', 'ttclid' => 'ttclid',
    'landing_url' => 'Landing URL', 'referrer' => 'Referrer', 'user_agent' => 'User agent',
];
$attrFilled = array_filter($attr, fn($k) => $lead[$k] !== null && $lead[$k] !== '', ARRAY_FILTER_USE_KEY);
$typeLabels = ['created' => 'Lead masuk', 'ingest_repeat' => 'Masuk lagi', 'stage' => 'Stage', 'update' => 'Data diubah', 'note' => 'Catatan'];
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
        <div class="actions"><button class="btn btn-primary" type="submit">Simpan perubahan</button></div>
      </form>
      <?php else: ?>
      <dl class="kv">
        <dt>Produk</dt><dd><?= e($lead['product']) ?: '-' ?></dd>
        <dt>Kota</dt><dd><?= e($lead['city']) ?: '-' ?></dd>
        <dt>Nilai quotation</dt><dd><?= e(fmt_money($lead['quote_value'])) ?: '-' ?></dd>
        <dt>Nilai deal</dt><dd><?= e(fmt_money($lead['deal_value'])) ?: '-' ?></dd>
        <dt>Follow up</dt><dd><?= e(fmt_date($lead['next_followup'])) ?: '-' ?></dd>
        <dt>Alasan lost</dt><dd><?= e($lead['lost_reason']) ?: '-' ?></dd>
      </dl>
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
  </aside>
</div>
