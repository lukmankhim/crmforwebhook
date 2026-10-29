<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? brand('name')) ?> · <?= e(brand('name')) ?></title>
<?= brand_head() ?>
<link rel="stylesheet" href="/assets/app.css">
<script src="/assets/app.js" defer></script>
</head>
<body class="bare">
<main id="main" class="bare__box">
  <p class="bare__brand">
    <?php if (brand('logo')): ?><img class="bare__logo" src="<?= e(brand('logo')) ?>" alt="<?= e(brand('name')) ?>"><?php else: ?><?= e(brand('name')) ?><?php endif; ?>
  </p>
  <?php foreach (take_flashes() as $f): ?>
    <div class="flash flash--<?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
