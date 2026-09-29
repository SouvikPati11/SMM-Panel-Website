<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title>Install · SMM Panel</title>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<?= $this->partial('partials/flash') ?>
<main class="container container-sm" style="padding-top:40px;padding-bottom:60px">
  <div class="flex items-center gap-1 mb-3"><span class="brand-mark">S</span><strong style="font-size:18px">SMM Panel installer</strong></div>
  <ol class="chips" aria-label="Steps">
    <li class="chip <?= ($step ?? 1) === 1 ? 'active' : '' ?>">1. Requirements</li>
    <li class="chip <?= ($step ?? 1) === 2 ? 'active' : '' ?>">2. Database</li>
    <li class="chip <?= ($step ?? 1) === 3 ? 'active' : '' ?>">3. Site & admin</li>
    <li class="chip <?= ($step ?? 1) === 4 ? 'active' : '' ?>">4. Finish</li>
  </ol>
  <?= $content ?>
</main>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
