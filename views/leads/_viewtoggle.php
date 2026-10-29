<nav class="segmented" aria-label="Tampilan">
  <a href="<?= e(url('/leads', array_merge($filters, ['view' => null, 'page' => null]))) ?>" <?= $filters['view'] === '' ? 'aria-current="page"' : '' ?>>Daftar</a>
  <a href="<?= e(url('/leads', array_merge($filters, ['view' => 'kanban', 'stage' => null, 'page' => null]))) ?>" <?= $filters['view'] === 'kanban' ? 'aria-current="page"' : '' ?>>Kanban</a>
</nav>
