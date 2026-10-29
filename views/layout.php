<?php
$user = current_user();
$tenant = current_tenant();
$nav = $nav ?? '';
$navItems = [];
if ($tenant && can('leads.view')) {
    $navItems['dashboard'] = ['/', 'Ringkasan'];
    $navItems['leads'] = ['/leads', 'Leads'];
    $navItems['sources'] = ['/sources', 'Sumber'];
}
if ($tenant && can('settings')) {
    $navItems['settings'] = ['/settings', 'Pengaturan'];
}
if (can('super')) {
    $navItems['tenants'] = ['/tenants', 'Tenant'];
    $navItems['audit'] = ['/audit', 'Audit'];
}
$allTenants = can('super') ? q('SELECT id, name, status FROM tenants ORDER BY name')->fetchAll() : [];
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? brand('name')) ?><?= $tenant ? ' · ' . e($tenant['name']) : '' ?> · <?= e(brand('name')) ?></title>
<?= brand_head() ?>
<link rel="stylesheet" href="/assets/app.css">
<?= brand_theme_link() ?>
<script src="/assets/app.js" defer></script>
</head>
<body>
<a class="skip" href="#main">Lewati ke konten</a>
<header class="app-header">
  <div class="app-header__row">
    <a class="brand" href="/">
      <?php if (brand('logo')): ?><img class="brand__logo" src="<?= e(brand('logo')) ?>" alt="<?= e(brand('name')) ?>"><?php else: ?><span class="brand__app"><?= e(brand('name')) ?></span><?php endif; ?>
      <span class="brand__text">
        <span class="brand__tenant"><?= e($tenant['name'] ?? 'Belum ada tenant') ?></span>
        <?php if ($tenant): ?><span class="brand__kind"><?= $tenant['kind'] === 'client' ? 'Client' : 'Bisnis sendiri' ?></span><?php endif; ?>
      </span>
    </a>
    <?php if ($allTenants): ?>
      <form class="switcher" method="post" action="/switch-tenant">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($currentPath) ?>">
        <label class="visually-hidden" for="switch-tenant">Pilih tenant</label>
        <select id="switch-tenant" name="tenant_id">
          <?php foreach ($allTenants as $t): ?>
            <option value="<?= (int) $t['id'] ?>" <?= $tenant && (int) $tenant['id'] === (int) $t['id'] ? 'selected' : '' ?>>
              <?= e($t['name']) ?><?= $t['status'] !== 'active' ? ' (nonaktif)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-quiet" type="submit">Ganti</button>
      </form>
    <?php endif; ?>
    <div class="usermenu">
      <a href="/account" <?= $nav === 'account' ? 'aria-current="page"' : '' ?>><?= e($user['name']) ?></a>
      <form method="post" action="/logout">
        <?= csrf_field() ?>
        <button class="btn btn-quiet" type="submit">Keluar</button>
      </form>
    </div>
  </div>
  <?php if ($navItems): ?>
  <nav class="mainnav" aria-label="Menu utama">
    <?php foreach ($navItems as $key => [$href, $label]): ?>
      <a href="<?= $href ?>" <?= $nav === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
</header>
<main id="main" class="container">
  <?php foreach (take_flashes() as $f): ?>
    <div class="flash flash--<?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
