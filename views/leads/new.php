<div class="page-head">
  <h1>Tambah lead</h1>
  <a class="btn btn-quiet" href="/leads">Kembali ke daftar</a>
</div>

<form class="card stack form-narrow" method="post" action="/leads">
  <?= csrf_field() ?>
  <div class="field">
    <label for="phone">Nomor HP <span class="req">wajib</span></label>
    <input id="phone" name="phone" type="tel" inputmode="tel" required value="<?= e(old('phone')) ?>" aria-describedby="phone-help">
    <p id="phone-help" class="muted small">Format bebas (0812..., +62 812...). Disimpan sebagai 62812...</p>
  </div>
  <div class="field">
    <label for="name">Nama</label>
    <input id="name" name="name" type="text" value="<?= e(old('name')) ?>">
  </div>
  <div class="grid-2">
    <div class="field">
      <label for="source">Sumber</label>
      <select id="source" name="source">
        <?php foreach (source_labels() as $key => $label): if ($key === 'import') continue; ?>
          <option value="<?= e($key) ?>" <?= old('source', 'manual') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="src_tag">Tag sumber / campaign</label>
      <input id="src_tag" name="src_tag" type="text" value="<?= e(old('src_tag')) ?>">
    </div>
    <div class="field">
      <label for="product">Produk</label>
      <input id="product" name="product" type="text" value="<?= e(old('product')) ?>">
    </div>
    <div class="field">
      <label for="city">Kota</label>
      <input id="city" name="city" type="text" value="<?= e(old('city')) ?>">
    </div>
    <div class="field">
      <label for="assigned_to">PIC</label>
      <select id="assigned_to" name="assigned_to">
        <option value="">Belum ada</option>
        <?php foreach ($members as $m): ?>
          <option value="<?= (int) $m['id'] ?>" <?= old('assigned_to') === (string) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="next_followup">Follow up berikutnya</label>
      <input id="next_followup" name="next_followup" type="date" value="<?= e(old('next_followup')) ?>">
    </div>
  </div>
  <div class="actions">
    <button class="btn btn-primary" type="submit">Simpan lead</button>
  </div>
</form>
