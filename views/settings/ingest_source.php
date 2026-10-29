<?php
$isGoogle = $source['adapter'] === 'google_lead_form';
$pretty = function (?string $json): string {
    $decoded = json_decode((string) $json, true);
    return is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $json;
};
$resultFields = ['external_id' => 'external_id', 'phone' => 'Nomor (sudah dinormalisasi)', 'name' => 'Nama', 'message' => 'Pesan', 'campaign' => 'Campaign', 'landing_url' => 'Landing URL', 'referrer' => 'Referrer', 'ad_id' => 'Ad ID', 'ctwa_clid' => 'ctwa_clid', 'fbclid' => 'fbclid', 'gclid' => 'gclid', 'gbraid' => 'gbraid', 'wbraid' => 'wbraid', 'ttclid' => 'ttclid'];
?>
<div class="page-head">
  <div>
    <p class="crumb"><a href="/settings/ingest">Webhook &amp; API</a></p>
    <h1><?= e($source['label']) ?></h1>
  </div>
</div>

<section class="card">
  <h2>Alamat webhook</h2>
  <div class="copyrow">
    <code class="secret breakall"><?= e($endpoint) ?></code>
    <button class="btn btn-quiet" type="button" data-copy="<?= e($endpoint) ?>">Salin URL</button>
  </div>
  <?php if ($source['auth_type'] === 'bearer'): ?>
    <p class="small">Kirim <code>POST</code> JSON dengan header <code>Authorization: Bearer &lt;token&gt;</code>. Token dibuat di halaman Webhook &amp; API.</p>
  <?php elseif ($source['auth_type'] === 'query'): ?>
    <p class="small warn-text">Token dikirim di URL (parameter <code>k</code>). Pakai hanya untuk platform yang tidak bisa mengatur header, karena URL bisa tercatat di log pihak lain. Aktifkan HMAC kalau platform mendukung.</p>
  <?php else: ?>
    <p class="small">Google Ads mengirim <code>google_key</code> di body. Isi secret di bawah dengan key yang sama seperti di pengaturan Lead Form.</p>
  <?php endif; ?>
</section>

<form class="card stack" method="post" action="/settings/ingest/sources/<?= (int) $source['id'] ?>">
  <?= csrf_field() ?>
  <h2>Pengaturan sumber</h2>
  <div class="grid-2">
    <div class="field"><label for="label">Nama</label><input id="label" name="label" type="text" required maxlength="100" value="<?= e($source['label']) ?>"></div>
    <div class="field">
      <label for="lead_source">Sumber lead di CRM</label>
      <select id="lead_source" name="lead_source">
        <?php foreach (source_labels() as $k => $l): ?><option value="<?= $k ?>" <?= $source['lead_source'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="auth_type">Autentikasi</label>
      <select id="auth_type" name="auth_type">
        <option value="bearer" <?= $source['auth_type'] === 'bearer' ? 'selected' : '' ?>>Header Bearer (disarankan)</option>
        <option value="query" <?= $source['auth_type'] === 'query' ? 'selected' : '' ?>>Token di URL</option>
        <option value="google_key" <?= $source['auth_type'] === 'google_key' ? 'selected' : '' ?>>google_key (Google Ads Lead Form)</option>
      </select>
    </div>
    <div class="field">
      <label for="signature_type">Signature</label>
      <select id="signature_type" name="signature_type">
        <option value="none" <?= $source['signature_type'] === 'none' ? 'selected' : '' ?>>Tidak ada</option>
        <option value="hmac_sha256" <?= $source['signature_type'] === 'hmac_sha256' ? 'selected' : '' ?>>HMAC SHA256 di header X-Signature</option>
      </select>
    </div>
    <div class="field">
      <label for="secret">Secret (HMAC atau google_key)</label>
      <input id="secret" name="secret" type="password" autocomplete="new-password" maxlength="200" aria-describedby="secret-help">
      <p id="secret-help" class="muted small"><?= $has_secret ? 'Secret sudah tersimpan (terenkripsi). Kosongkan kalau tidak diganti.' : 'Belum ada secret.' ?></p>
      <?php if ($has_secret): ?><label class="check"><input type="checkbox" name="clear_secret" value="1"> Hapus secret</label><?php endif; ?>
    </div>
    <label class="check"><input type="checkbox" name="active" value="1" <?= $source['active'] ? 'checked' : '' ?>> Sumber aktif</label>
  </div>
  <div class="field">
    <label for="mapping_json">Mapping field (JSON)</label>
    <textarea id="mapping_json" name="mapping_json" rows="14" class="code" spellcheck="false" <?= $isGoogle ? 'readonly' : '' ?>><?= e($pretty($source['mapping_json'])) ?></textarea>
    <p class="muted small">
      <?php if ($isGoogle): ?>
        Google Lead Form dibaca dari format resminya (user_column_data, lead_id, gcl_id), mapping tidak dipakai.
      <?php else: ?>
        Path memakai titik untuk objek dan [0] untuk array, contoh <code>customer.phone_number</code> atau <code>contacts[0].wa_id</code>.
        Key yang dikenal: external_id_path, phone_path (wajib), name_path, message_path, campaign_path, landing_path, referrer_path, ad_id_path, user_agent_path, click_id_paths.
        Stage, status, dan nominal dari luar tidak pernah dibaca.
      <?php endif; ?>
    </p>
  </div>
  <div class="actions"><button class="btn btn-primary" type="submit">Simpan sumber</button></div>
</form>

<section class="card">
  <h2>Uji mapping</h2>
  <p class="muted small">Tempel contoh payload untuk melihat hasil baca. Tidak ada lead yang disimpan.<?= $last ? ' Terisi otomatis dengan payload terakhir yang masuk (#' . (int) $last['id'] . ').' : '' ?></p>
  <form method="post" action="/settings/ingest/sources/<?= (int) $source['id'] ?>/test" class="stack">
    <?= csrf_field() ?>
    <div class="field">
      <label for="sample">Contoh payload JSON</label>
      <textarea id="sample" name="sample" rows="10" class="code" spellcheck="false" required><?= e($pretty($sample)) ?></textarea>
    </div>
    <div class="actions"><button class="btn" type="submit">Uji</button></div>
  </form>
  <?php if ($test !== null): ?>
    <div class="test-result" role="status">
      <?php if (isset($test['error'])): ?>
        <p class="flash flash--error"><?= e($test['error']) ?></p>
      <?php else: ?>
        <p class="flash flash--success">Payload terbaca. Lead akan masuk dengan data berikut.</p>
      <?php endif; ?>
      <dl class="kv kv--wrap">
        <?php foreach ($resultFields as $k => $label): if (!isset($test[$k]) || $test[$k] === null || $test[$k] === '') continue; ?>
          <dt><?= e($label) ?></dt><dd class="breakall"><?= e($test[$k]) ?></dd>
        <?php endforeach; ?>
      </dl>
    </div>
  <?php endif; ?>
</section>
