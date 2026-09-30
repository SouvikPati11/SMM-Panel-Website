<?php $meta = \App\Services\SeoService::meta(['title' => $title ?? null, 'robots' => 'noindex,follow']); ?>
<!doctype html>
<html lang="en">
<head><?= $this->partial('partials/head', ['meta' => $meta]) ?></head>
<body class="auth-body">
<div class="auth-wrap">
  <aside class="auth-side">
    <a class="brand-row" href="<?= e(url('/')) ?>" style="color:#fff"><?= $this->partial('partials/brand') ?></a>
    <div>
      <h2><?= e($sideTitle ?? 'Grow every account from one fast dashboard.') ?></h2>
      <ul class="auth-points">
        <li><span class="auth-point-icon"><?= icon('zap') ?></span><span><strong>Instant orders</strong>Live, server-calculated pricing before you pay.</span></li>
        <li><span class="auth-point-icon"><?= icon('refresh') ?></span><span><strong>Automatic refunds</strong>Partial or failed orders are refunded to your balance.</span></li>
        <li><span class="auth-point-icon"><?= icon('code') ?></span><span><strong>Reseller API</strong>Compatible with the standard SMM panel API.</span></li>
        <li><span class="auth-point-icon"><?= icon('shield') ?></span><span><strong>Secure wallet</strong>Every balance change is recorded; 2FA available.</span></li>
      </ul>
    </div>
    <p class="text-sm" style="opacity:.75">&copy; <?= date('Y') ?> <?= e(site_name()) ?></p>
  </aside>
  <main class="auth-main" id="main">
    <div class="auth-box">
      <a class="brand-row auth-mobile-brand" href="<?= e(url('/')) ?>"><?= $this->partial('partials/brand') ?></a>
      <div class="auth-card">
        <?= $this->partial('partials/auth-alerts') ?>
        <?= $content ?>
      </div>
    </div>
  </main>
</div>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
