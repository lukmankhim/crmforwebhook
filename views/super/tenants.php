<h1>Tenant</h1>

<section class="card">
  <h2>Semua tenant</h2>
  <?php if (!$tenants): ?>
    <p class="empty-inline">Belum ada tenant. Buat tenant pertama di bawah.</p>
  <?php else: ?>
  <table class="table table--cards">
    <thead><tr><th>Nama</th><th>Slug</th><th>Pengguna</th><th>Lead</th><th>Jenis dan status</th><th>Buka</th></tr></thead>
    <tbody>
    <?php foreach ($tenants as $t): ?>
      <tr class="<?= $t['status'] === 'active' ? '' : 'is-off' ?>">
        <td data-label="Nama"><?= e($t['name']) ?></td>
        <td data-label="Slug"><code><?= e($t['slug']) ?></code></td>
        <td data-label="Pengguna" class="num"><?= (int) $t['user_count'] ?></td>
        <td data-label="Lead" class="num"><?= number_format((int) $t['lead_count'], 0, ',', '.') ?></td>
        <td data-label="Jenis dan status">
          <form method="post" action="/tenants/<?= (int) $t['id'] ?>" class="inline-form inline-form--tight">
            <?= csrf_field() ?>
            <label class="visually-hidden" for="kind-<?= (int) $t['id'] ?>">Jenis <?= e($t['name']) ?></label>
            <select id="kind-<?= (int) $t['id'] ?>" name="kind">
              <option value="own" <?= $t['kind'] === 'own' ? 'selected' : '' ?>>Bisnis sendiri</option>
              <option value="client" <?= $t['kind'] === 'client' ? 'selected' : '' ?>>Client</option>
            </select>
            <label class="visually-hidden" for="status-<?= (int) $t['id'] ?>">Status <?= e($t['name']) ?></label>
            <select id="status-<?= (int) $t['id'] ?>" name="status">
              <option value="active" <?= $t['status'] === 'active' ? 'selected' : '' ?>>Aktif</option>
              <option value="suspended" <?= $t['status'] === 'suspended' ? 'selected' : '' ?>>Nonaktif</option>
            </select>
            <button class="btn btn-quiet" type="submit">Simpan</button>
          </form>
        </td>
        <td data-label="Buka">
          <form method="post" action="/switch-tenant">
            <?= csrf_field() ?>
            <input type="hidden" name="tenant_id" value="<?= (int) $t['id'] ?>">
            <input type="hidden" name="back" value="/leads">
            <button class="btn" type="submit">Buka</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Buat tenant</h2>
  <form method="post" action="/tenants" class="stack">
    <?= csrf_field() ?>
    <div class="grid-2">
      <div class="field"><label for="t-name">Nama</label><input id="t-name" name="name" type="text" required maxlength="150" value="<?= e(old('name')) ?>"></div>
      <div class="field">
        <label for="t-slug">Slug</label>
        <input id="t-slug" name="slug" type="text" required pattern="[a-z0-9\-]{2,64}" maxlength="64" value="<?= e(old('slug')) ?>" aria-describedby="t-slug-help">
        <p id="t-slug-help" class="muted small">Huruf kecil, angka, minus. Dipakai di URL webhook dan tidak bisa diganti.</p>
      </div>
      <div class="field">
        <label for="t-kind">Jenis</label>
        <select id="t-kind" name="kind">
          <option value="own" <?= old('kind', 'own') === 'own' ? 'selected' : '' ?>>Bisnis sendiri</option>
          <option value="client" <?= old('kind') === 'client' ? 'selected' : '' ?>>Client</option>
        </select>
      </div>
    </div>
    <p class="muted small">Tenant baru otomatis dapat pipeline standar (Baru, Dihubungi, Qualified, Quotation, Closing, Lost) yang bisa diubah.</p>
    <div class="actions"><button class="btn btn-primary" type="submit">Buat tenant</button></div>
  </form>
</section>
