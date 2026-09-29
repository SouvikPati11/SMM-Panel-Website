<?php $meta = \App\Services\SeoService::meta(['title' => $title ?? null, 'robots' => 'noindex,follow']); ?>
<!doctype html>
<html lang="en">
<head><?= $this->partial('partials/head', ['meta' => $meta]) ?></head>
<body>
<?= $this->partial('partials/flash') ?>
<div class="auth-wrap">
  <aside class="auth-side">
    <a class="brand-row" href="<?= e(url('/')) ?>" style="color:#fff"><?= $this->partial('partials/brand') ?></a>
    <div>
      <h2><?= e($sideTitle ?? 'Grow every account from one fast dashboard.') ?></h2>
      <ul class="mt-3">
        <li><?= icon('zap') ?> Instant order placement with live pricing</li>
        <li><?= icon('refresh') ?> Automatic refunds for partial or failed orders</li>
        <li><?= icon('code') ?> Reseller API compatible with standard panels</li>
        <li><?= icon('shield') ?> Secure wallet with two-factor authentication</li>
      </ul>
    </div>
    <p class="text-sm" style="opacity:.75">&copy; <?= date('Y') ?> <?= e(site_name()) ?></p>
  </aside>
  <main class="auth-main" id="main">
    <div class="auth-box">
      <div class="show-mobile mb-3"><a class="brand-row" href="<?= e(url('/')) ?>"><?= $this->partial('partials/brand') ?></a></div>
      <?= $content ?>
    </div>
  </main>
</div>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
