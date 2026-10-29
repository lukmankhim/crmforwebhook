<h1>Pengaturan</h1>
<?= render_partial('settings/_tabs', ['tab' => $tab]) ?>

<section class="card">
  <h2>Stage pipeline</h2>
  <p class="muted small">Urutan menentukan posisi di funnel. Lead dari webhook selalu masuk ke stage "baru". Tanggal qualified tercatat saat lead mencapai stage "qualified" atau sesudahnya.</p>
  <form method="post" action="/settings/pipeline" id="pipeline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
  </form>
  <table class="table table--cards">
    <thead><tr><th>Kode</th><th>Label</th><th>Urutan</th><th>Jenis</th><th>Lead</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($stages as $key => $s): ?>
      <tr>
        <td data-label="Kode"><code><?= e($key) ?></code></td>
        <td data-label="Label">
          <label class="visually-hidden" for="label-<?= e($key) ?>">Label <?= e($key) ?></label>
          <input form="pipeline-form" id="label-<?= e($key) ?>" name="stages[<?= e($key) ?>][label]" type="text" maxlength="80" value="<?= e($s['label']) ?>">
        </td>
        <td data-label="Urutan">
          <label class="visually-hidden" for="sort-<?= e($key) ?>">Urutan <?= e($key) ?></label>
          <input form="pipeline-form" id="sort-<?= e($key) ?>" class="input-short" name="stages[<?= e($key) ?>][sort]" type="number" step="1" value="<?= (int) $s['sort'] ?>">
        </td>
        <td data-label="Jenis">
          <label class="visually-hidden" for="kind-<?= e($key) ?>">Jenis <?= e($key) ?></label>
          <select form="pipeline-form" id="kind-<?= e($key) ?>" name="stages[<?= e($key) ?>][kind]" <?= $key === 'baru' ? 'disabled' : '' ?>>
            <?php foreach (['open' => 'Berjalan', 'won' => 'Menang (closing)', 'lost' => 'Kalah (lost)'] as $k => $label): ?>
              <option value="<?= $k ?>" <?= $s['kind'] === $k ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td data-label="Lead" class="num"><?= $usage[$key] ?? 0 ?></td>
        <td data-label="Aksi">
          <?php if ($key !== 'baru' && empty($usage[$key])): ?>
          <form method="post" action="/settings/pipeline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="stage_key" value="<?= e($key) ?>">
            <button class="btn btn-danger" type="submit" data-confirm="Hapus stage <?= e($s['label']) ?>?">Hapus</button>
          </form>
          <?php else: ?>
            <span class="muted small"><?= $key === 'baru' ? 'Stage masuk' : 'Masih dipakai' ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="actions"><button form="pipeline-form" class="btn btn-primary" type="submit">Simpan perubahan</button></div>
</section>

<section class="card">
  <h2>Tambah stage</h2>
  <form method="post" action="/settings/pipeline" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div class="field"><label for="new-label">Nama stage</label><input id="new-label" name="label" type="text" maxlength="80" required></div>
    <div class="field">
      <label for="new-kind">Jenis</label>
      <select id="new-kind" name="kind">
        <option value="open">Berjalan</option><option value="won">Menang (closing)</option><option value="lost">Kalah (lost)</option>
      </select>
    </div>
    <button class="btn" type="submit">Tambah</button>
  </form>
</section>
