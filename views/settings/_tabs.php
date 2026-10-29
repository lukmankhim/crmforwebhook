<?php $tabs = ['tenant' => ['/settings/tenant', 'Tenant'], 'users' => ['/settings/users', 'Pengguna'], 'pipeline' => ['/settings/pipeline', 'Pipeline'], 'ingest' => ['/settings/ingest', 'Webhook & API'], 'integrations' => ['/settings/integrations', 'Integrasi']]; ?>
<nav class="tabs" aria-label="Bagian pengaturan">
  <?php foreach ($tabs as $key => [$href, $label]): ?>
    <a href="<?= $href ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
