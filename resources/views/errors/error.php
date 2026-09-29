<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?></title>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<main class="auth-main" style="min-height:100vh">
  <div class="card" style="max-width:520px;width:100%">
    <div class="card-body" style="padding:32px">
      <div class="badge badge-primary no-dot mb-2">Error <?= (int) $status ?></div>
      <h1><?= e($title) ?></h1>
      <p class="text-muted" style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e($text) ?></p>
      <div class="btn-group mt-2">
        <a class="btn btn-primary" href="<?= e(url('/')) ?>"><?= icon('home') ?> Home</a>
        <?php if ((int) $status === 419): ?><a class="btn btn-secondary" href="">Reload page</a><?php endif ?>
      </div>
    </div>
  </div>
</main>
</body>
</html>
