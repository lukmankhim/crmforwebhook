<h1>Pengaturan</h1>
<?= render_partial('settings/_tabs', ['tab' => $tab]) ?>

<form class="card stack form-narrow" method="post" action="/settings/tenant">
  <?= csrf_field() ?>
  <div class="field">
    <label for="name">Nama tenant</label>
    <input id="name" name="name" type="text" required maxlength="150" value="<?= e($tenant['name']) ?>">
  </div>
  <div class="field">
    <label for="timezone">Zona waktu tampilan</label>
    <select id="timezone" name="timezone">
      <?php foreach (array_unique(array_merge($timezones, [$tenant['timezone']])) as $tz): ?>
        <option value="<?= e($tz) ?>" <?= $tenant['timezone'] === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <dl class="kv">
    <dt>Slug (dipakai di URL webhook)</dt><dd><code><?= e($tenant['slug']) ?></code></dd>
    <dt>Jenis</dt><dd><?= $tenant['kind'] === 'client' ? 'Client' : 'Bisnis sendiri' ?></dd>
  </dl>
  <div class="actions"><button class="btn btn-primary" type="submit">Simpan</button></div>
</form>
