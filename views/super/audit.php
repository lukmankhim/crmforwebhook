<h1>Audit log</h1>

<form class="filters" method="get" action="/audit">
  <div class="field">
    <label for="a-tenant">Tenant</label>
    <select id="a-tenant" name="tenant">
      <option value="">Semua</option>
      <?php foreach ($tenants as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (string) $filters['tenant'] === (string) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="a-action">Aksi</label>
    <select id="a-action" name="action">
      <option value="">Semua</option>
      <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $filters['action'] === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="filters__actions">
    <button class="btn" type="submit">Terapkan</button>
    <?php if ($filters['tenant'] || $filters['action']): ?><a class="btn btn-quiet" href="/audit">Reset</a><?php endif; ?>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="empty"><p>Tidak ada catatan.</p></div>
<?php else: ?>
<table class="table table--cards">
  <thead><tr><th>Waktu</th><th>Tenant</th><th>User</th><th>Aksi</th><th>Objek</th><th>Detail</th><th>IP</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td data-label="Waktu" class="nowrap"><?= e(fmt_dt($r['created_at'], 'd M Y H:i:s')) ?></td>
      <td data-label="Tenant"><?= e($r['tenant_name'] ?? '-') ?></td>
      <td data-label="User"><?= e($r['username'] ?? '-') ?></td>
      <td data-label="Aksi"><code><?= e($r['action']) ?></code></td>
      <td data-label="Objek"><?= e(trim(($r['object'] ?? '') . ' ' . ($r['object_id'] ?? ''))) ?: '-' ?></td>
      <td data-label="Detail" class="breakall small"><?= e($r['detail'] ?? '') ?></td>
      <td data-label="IP"><?= e($r['ip'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<nav class="pager" aria-label="Halaman">
  <?php if ($page > 1): ?><a class="btn btn-quiet" href="<?= e(url('/audit', $filters + ['page' => $page - 1])) ?>">Lebih baru</a><?php endif; ?>
  <span>Halaman <?= $page ?></span>
  <?php if ($has_more): ?><a class="btn btn-quiet" href="<?= e(url('/audit', $filters + ['page' => $page + 1])) ?>">Lebih lama</a><?php endif; ?>
</nav>
<?php endif; ?>
