<?php
/** @var array $meta from SeoService::meta() */
$meta = $meta ?? \App\Services\SeoService::meta(['title' => $title ?? null, 'robots' => $robots ?? 'index,follow']);
$favicon = setting('site_favicon');
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($meta['title']) ?></title>
<?php if ($meta['description'] !== ''): ?><meta name="description" content="<?= e($meta['description']) ?>"><?php endif ?>
<?php if (setting('seo_keywords')): ?><meta name="keywords" content="<?= e(setting('seo_keywords')) ?>"><?php endif ?>
<meta name="robots" content="<?= e($meta['robots']) ?>">
<link rel="canonical" href="<?= e($meta['canonical']) ?>">
<meta property="og:site_name" content="<?= e(site_name()) ?>">
<meta property="og:title" content="<?= e($meta['title']) ?>">
<meta property="og:description" content="<?= e($meta['description']) ?>">
<meta property="og:type" content="<?= e($meta['type']) ?>">
<meta property="og:url" content="<?= e($meta['canonical']) ?>">
<meta property="og:locale" content="en_US">
<?php if ($meta['image']): ?><meta property="og:image" content="<?= e($meta['image']) ?>"><?php if ($meta['image'] === \App\Services\SeoService::defaultImage()): ?><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><meta property="og:image:alt" content="<?= e(site_name()) ?> — social media growth panel"><?php endif ?><meta name="twitter:image" content="<?= e($meta['image']) ?>"><?php endif ?>
<meta name="twitter:card" content="<?= $meta['image'] ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($meta['title']) ?>">
<?php if ($meta['description'] !== ''): ?><meta name="twitter:description" content="<?= e($meta['description']) ?>"><?php endif ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="theme-color" content="#5b4dff">
<?php if ($favicon): ?><link rel="icon" href="<?= e(upload_url($favicon)) ?>"><?php else: ?><link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml"><?php endif ?>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script type="application/json" id="currency-config"><?= json_encode(\App\Services\CurrencyService::clientConfig(), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php foreach ((array) $meta['jsonld'] as $ld): ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endforeach ?>
