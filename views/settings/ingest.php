<?php
$statusLabels = ['received' => 'Antre', 'retry' => 'Coba ulang', 'processed' => 'Diproses', 'failed' => 'Gagal', 'rejected' => 'Ditolak'];
$authLabels = ['bearer' => 'Header Bearer', 'query' => 'Token di URL', 'google_key' => 'google_key'];
?>
<h1>Pengaturan</h1>
<?= render_partial('settings/_tabs', ['tab' => $tab]) ?>

<?php if ($shown_token): ?>
  <div class="card reveal" role="status">
    <p>Token <strong><?= e($shown_token['name']) ?></strong>. Simpan di tempat aman, token ini tidak bisa ditampilkan lagi.</p>
    <div class="copyrow">
      <code class="secret breakall"><?= e($shown_token['token']) ?></code>
      <button class="btn btn-quiet" type="button" data-copy="<?= e($shown_token['token']) ?>">Salin token</button>
    </div>
  </div>
<?php endif; ?>

<section class="card">
  <h2>Sumber webhook</h2>
  <p class="muted small">Satu endpoint untuk semua platform. Tiap sumber punya mapping field sendiri, jadi platform baru cukup ditambah di sini tanpa ubah kode.</p>
  <?php if (!$sources): ?>
    <p class="empty-inline">Belum ada sumber. Tambahkan di bawah, misalnya "landing-page" (Generic JSON).</p>
  <?php else: ?>
  <table class="table table--cards">
    <thead><tr><th>Sumber</th><th>Jenis</th><th>Autentikasi</th><th>Masuk 7 hari</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($sources as $s): ?>
      <tr class="<?= $s['active'] ? '' : 'is-off' ?>">
        <td data-label="Sumber"><a class="rowlink" href="/settings/ingest/sources/<?= (int) $s['id'] ?>"><?= e($s['label']) ?></a> <code class="small"><?= e($s['slug']) ?></code></td>
        <td data-label="Jenis"><?= e($adapters[$s['adapter']]['label'] ?? $s['adapter']) ?></td>
        <td data-label="Autentikasi"><?= e($authLabels[$s['auth_type']]) ?><?= $s['signature_type'] === 'hmac_sha256' ? ' + HMAC' : '' ?></td>
        <td data-label="Masuk 7 hari" class="num"><?= (int) $s['hits_7d'] ?></td>
        <td data-label="Status"><?= $s['active'] ? 'Aktif' : 'Nonaktif' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <form method="post" action="/settings/ingest/sources" class="inline-form">
    <?= csrf_field() ?>
    <div class="field"><label for="src-label">Nama</label><input id="src-label" name="label" type="text" required maxlength="100" placeholder="Landing page"></div>
    <div class="field"><label for="src-slug">Kode (di URL)</label><input id="src-slug" name="slug" type="text" required pattern="[a-z0-9\-]{2,64}" maxlength="64" placeholder="landing-page"></div>
    <div class="field">
      <label for="src-adapter">Jenis</label>
      <select id="src-adapter" name="adapter">
        <?php foreach ($adapters as $key => $a): ?><option value="<?= e($key) ?>"><?= e($a['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <button class="btn" type="submit">Tambah sumber</button>
  </form>
</section>

<section class="card">
  <h2>Token API</h2>
  <p class="muted small">Dipakai di header <code>Authorization: Bearer &lt;token&gt;</code>. Disimpan sebagai hash, jadi kalau hilang buat token baru lalu cabut yang lama.</p>
  <?php if (!$keys): ?>
    <p class="empty-inline">Belum ada token.</p>
  <?php else: ?>
  <table class="table table--cards">
    <thead><tr><th>Nama</th><th>Awalan</th><th>Dibuat</th><th>Terakhir dipakai</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($keys as $k): ?>
      <tr class="<?= $k['active'] ? '' : 'is-off' ?>">
        <td data-label="Nama"><?= e($k['name']) ?></td>
        <td data-label="Awalan"><code><?= e($k['key_prefix']) ?>…</code></td>
        <td data-label="Dibuat"><?= e(fmt_dt($k['created_at'])) ?></td>
        <td data-label="Terakhir dipakai"><?= e(fmt_dt($k['last_used_at'])) ?: '<span class="muted">Belum</span>' ?></td>
        <td data-label="Status">
          <?php if ($k['active']): ?>
          <form method="post" action="/settings/ingest/keys/<?= (int) $k['id'] ?>/revoke">
            <?= csrf_field() ?>
            <button class="btn btn-danger" type="submit" data-confirm="Cabut token <?= e($k['name']) ?>? Semua pengirim yang memakainya akan ditolak.">Cabut</button>
          </form>
          <?php else: ?>Dicabut<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <form method="post" action="/settings/ingest/keys" class="inline-form">
    <?= csrf_field() ?>
    <div class="field"><label for="key-name">Nama token</label><input id="key-name" name="name" type="text" required maxlength="100" placeholder="Landing page"></div>
    <button class="btn" type="submit">Buat token</button>
  </form>
</section>

<section class="card">
  <h2>Log masuk</h2>
  <form method="get" action="/settings/ingest" class="inline-form">
    <div class="field">
      <label for="log-status">Status</label>
      <select id="log-status" name="status">
        <option value="">Semua</option>
        <?php foreach ($statuses as $st): ?><option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= $statusLabels[$st] ?></option><?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-quiet" type="submit">Saring</button>
  </form>
  <?php if (!$logs): ?>
    <p class="empty-inline"><?= $status ? 'Tidak ada log dengan status ini.' : 'Belum ada request masuk.' ?></p>
  <?php else: ?>
  <table class="table table--cards">
    <thead><tr><th>#</th><th>Waktu</th><th>Sumber</th><th>Status</th><th>Percobaan</th><th>Keterangan</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $g): ?>
      <tr>
        <td data-label="#"><a class="rowlink" href="/settings/ingest/log/<?= (int) $g['id'] ?>"><?= (int) $g['id'] ?></a></td>
        <td data-label="Waktu" class="nowrap"><?= e(fmt_dt($g['created_at'], 'd M H:i:s')) ?></td>
        <td data-label="Sumber"><?= e($g['source']) ?></td>
        <td data-label="Status"><span class="status status--<?= e($g['status']) ?>"><?= e($statusLabels[$g['status']]) ?></span></td>
        <td data-label="Percobaan" class="num"><?= (int) $g['attempts'] ?></td>
        <td data-label="Keterangan">
          <?php if ($g['lead_id']): ?><a href="/leads/<?= (int) $g['lead_id'] ?>">Lihat lead</a><?php endif; ?>
          <?php if ($g['error']): ?><span class="error-text"><?= e($g['error']) ?></span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
