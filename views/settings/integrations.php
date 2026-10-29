<h1>Pengaturan</h1>
<?= render_partial('settings/_tabs', ['tab' => $tab]) ?>

<p class="muted small">Integrasi bersifat opsional per tenant. Tenant yang tidak menyambungkan apa pun tetap jalan normal, hanya halaman Sumber yang kosong.</p>

<?php if (!$refs): ?>
  <div class="card flash--info">
    <p><strong>Belum ada kredensial Google di server.</strong></p>
    <p class="small">Superadmin menaruh file JSON service account di server (di luar folder aplikasi, chmod 600), lalu mendaftarkan path-nya di <code>config/config.php</code> bagian <code>google.credentials</code>. Langkahnya ada di docs/DEPLOY.md.</p>
  </div>
<?php endif; ?>

<?php foreach ($providers as $key => $p): $i = $integrations[$key] ?? null; $email = $i ? ($emails[$i['credential_ref']] ?? null) : null; ?>
<section class="card" aria-labelledby="int-<?= $key ?>">
  <div class="page-head page-head--card">
    <h2 id="int-<?= $key ?>"><?= e($p['label']) ?></h2>
    <span class="status <?= !$i ? '' : ($i['status'] === 'paused' ? 'status--retry' : ($i['last_error'] ? 'status--failed' : ($i['last_success_at'] ? 'status--processed' : 'status--retry'))) ?>">
      <?= !$i ? 'Belum tersambung' : ($i['status'] === 'paused' ? 'Dijeda' : ($i['last_error'] ? 'Error' : ($i['last_success_at'] ? 'Aktif' : 'Menunggu sync pertama'))) ?>
    </span>
  </div>

  <?php if ($test_result && $test_result['provider'] === $key): ?>
    <p class="flash flash--<?= $test_result['ok'] ? 'success' : 'error' ?>" role="status"><?= e($test_result['message']) ?></p>
  <?php endif; ?>

  <?php if ($i): ?>
  <dl class="kv kv--wrap">
    <dt><?= e($p['account_label']) ?></dt><dd class="breakall"><code><?= e($i['account_ref']) ?></code></dd>
    <dt>Sync terakhir berhasil</dt><dd><?= e(fmt_dt($i['last_success_at'])) ?: 'Belum pernah' ?></dd>
    <?php if ($i['last_error']): ?><dt>Error terakhir</dt><dd class="error-text breakall"><?= e($i['last_error']) ?></dd><?php endif; ?>
    <?php if ($i['sync_requested_at']): ?><dt>Sync berikutnya</dt><dd>Dalam 1 menit (sudah dijadwalkan)</dd><?php endif; ?>
  </dl>
  <div class="btn-row">
    <form method="post" action="/settings/integrations"><?= csrf_field() ?><input type="hidden" name="provider" value="<?= $key ?>"><input type="hidden" name="action" value="test"><button class="btn" type="submit">Tes koneksi</button></form>
    <?php if ($i['status'] === 'active'): ?>
      <form method="post" action="/settings/integrations"><?= csrf_field() ?><input type="hidden" name="provider" value="<?= $key ?>"><input type="hidden" name="action" value="sync"><button class="btn btn-quiet" type="submit">Sync sekarang</button></form>
      <form method="post" action="/settings/integrations"><?= csrf_field() ?><input type="hidden" name="provider" value="<?= $key ?>"><input type="hidden" name="action" value="pause"><button class="btn btn-quiet" type="submit">Jeda</button></form>
    <?php else: ?>
      <form method="post" action="/settings/integrations"><?= csrf_field() ?><input type="hidden" name="provider" value="<?= $key ?>"><input type="hidden" name="action" value="resume"><button class="btn btn-quiet" type="submit">Aktifkan lagi</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($refs): ?>
  <details class="int-form" <?= $i ? '' : 'open' ?>>
    <summary><?= $i ? 'Ubah sambungan' : 'Sambungkan' ?></summary>
    <ol class="steps">
      <li><?= e($p['grant_help']) ?>
        <?php foreach ($emails as $ref => $mail): if ($mail === null) continue; ?>
          <div class="copyrow"><code class="secret breakall"><?= e($mail) ?></code><button class="btn btn-quiet" type="button" data-copy="<?= e($mail) ?>">Salin email</button></div>
        <?php endforeach; ?>
      </li>
      <li>Isi <?= e(strtolower($p['account_label'])) ?> lalu simpan. Setelah tersimpan, klik Tes koneksi.</li>
    </ol>
    <form method="post" action="/settings/integrations" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="provider" value="<?= $key ?>">
      <input type="hidden" name="action" value="save">
      <div class="field">
        <label for="acc-<?= $key ?>"><?= e($p['account_label']) ?></label>
        <input id="acc-<?= $key ?>" name="account_ref" type="text" required maxlength="255" value="<?= e($i['account_ref'] ?? '') ?>" aria-describedby="acc-help-<?= $key ?>">
        <p id="acc-help-<?= $key ?>" class="muted small"><?= e($p['account_help']) ?></p>
      </div>
      <div class="field">
        <label for="cred-<?= $key ?>">Kredensial</label>
        <select id="cred-<?= $key ?>" name="credential_ref">
          <?php foreach ($refs as $ref => $_): ?>
            <option value="<?= e($ref) ?>" <?= ($i['credential_ref'] ?? '') === $ref ? 'selected' : '' ?>><?= e($ref) ?><?= $emails[$ref] === null ? ' (file tidak terbaca)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" type="submit">Simpan</button>
    </form>
  </details>
  <?php endif; ?>

  <?php if ($i): ?>
  <details class="int-form">
    <summary>Putuskan sambungan</summary>
    <form method="post" action="/settings/integrations" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="provider" value="<?= $key ?>">
      <input type="hidden" name="action" value="disconnect">
      <label class="check"><input type="checkbox" name="delete_data" value="1"> Hapus juga data <?= e($p['label']) ?> yang sudah ditarik</label>
      <div><button class="btn btn-danger" type="submit" data-confirm="Putuskan <?= e($p['label']) ?>?">Putuskan</button></div>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<section class="card">
  <h2>Riwayat sync</h2>
  <?php if (!$runs): ?>
    <p class="empty-inline">Belum ada sync.</p>
  <?php else: ?>
  <table class="table table--cards">
    <thead><tr><th>Mulai</th><th>Integrasi</th><th>Rentang</th><th>Status</th><th>Baris</th><th>Error</th></tr></thead>
    <tbody>
      <?php foreach ($runs as $r): ?>
        <tr>
          <td data-label="Mulai" class="nowrap"><?= e(fmt_dt($r['started_at'], 'd M H:i')) ?></td>
          <td data-label="Integrasi"><?= e($providers[$r['provider']]['label'] ?? $r['provider']) ?></td>
          <td data-label="Rentang" class="nowrap"><?= e(fmt_date($r['date_from'])) ?> sampai <?= e(fmt_date($r['date_to'])) ?></td>
          <td data-label="Status"><span class="status status--<?= $r['status'] === 'success' ? 'processed' : ($r['status'] === 'failed' ? 'failed' : 'retry') ?>"><?= ['success' => 'Berhasil', 'failed' => 'Gagal', 'running' => 'Berjalan'][$r['status']] ?></span></td>
          <td data-label="Baris" class="num"><?= number_format((int) $r['rows'], 0, ',', '.') ?></td>
          <td data-label="Error" class="small breakall"><?= e($r['error'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
