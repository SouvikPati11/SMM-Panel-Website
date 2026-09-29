<?php
$meta = $meta ?? \App\Services\SeoService::meta(['title' => $title ?? null, 'description' => $description ?? null]);
$user = auth_user();
$footerPages = db()->fetchAll("SELECT slug, title FROM pages WHERE status = 'published' AND show_in_footer = 1 ORDER BY id");
?>
<!doctype html>
<html lang="en">
<head>
<?= $this->partial('partials/head', ['meta' => $meta]) ?>
<?php if (setting('seo_google_verification')): ?><meta name="google-site-verification" content="<?= e(setting('seo_google_verification')) ?>"><?php endif ?>
<?php if (setting('seo_bing_verification')): ?><meta name="msvalidate.01" content="<?= e(setting('seo_bing_verification')) ?>"><?php endif ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
  <div class="container site-nav">
    <a class="brand" href="<?= e(url('/')) ?>"><?= $this->partial('partials/brand') ?></a>
    <nav class="site-links" aria-label="Main">
      <a href="<?= e(url('/services')) ?>" class="<?= is_active_path('/services') ? 'active' : '' ?>">Services</a>
      <a href="<?= e(url('/api-docs')) ?>" class="<?= is_active_path('/api-docs') ? 'active' : '' ?>">API</a>
      <?php if (setting('blog_enabled', '1') === '1'): ?><a href="<?= e(url('/blog')) ?>" class="<?= is_active_path('/blog') ? 'active' : '' ?>">Blog</a><?php endif ?>
      <a href="<?= e(url('/faq')) ?>" class="<?= is_active_path('/faq') ? 'active' : '' ?>">FAQ</a>
      <a href="<?= e(url('/contact')) ?>" class="<?= is_active_path('/contact') ? 'active' : '' ?>">Contact</a>
    </nav>
    <div class="actions">
      <button class="btn btn-ghost btn-icon" type="button" data-theme-toggle aria-label="Toggle dark mode"><?= icon('moon') ?></button>
      <?php if ($user): ?>
        <a class="btn btn-primary" href="<?= e(url('/dashboard')) ?>"><?= icon('dashboard') ?> Dashboard</a>
      <?php else: ?>
        <a class="btn btn-secondary" href="<?= e(url('/login')) ?>">Sign in</a>
        <?php if (setting('registration_enabled', '1') === '1'): ?><a class="btn btn-primary" href="<?= e(url('/register')) ?>">Get started</a><?php else: ?><a class="btn btn-primary show-mobile" href="<?= e(url('/login')) ?>">Sign in</a><?php endif ?>
      <?php endif ?>
      <details class="dropdown mobile-menu">
        <summary class="btn btn-ghost btn-icon" aria-label="Menu"><?= icon('menu') ?></summary>
        <div class="dropdown-menu">
          <a href="<?= e(url('/services')) ?>"><?= icon('layers') ?> Services</a>
          <a href="<?= e(url('/api-docs')) ?>"><?= icon('code') ?> API</a>
          <?php if (setting('blog_enabled', '1') === '1'): ?><a href="<?= e(url('/blog')) ?>"><?= icon('book') ?> Blog</a><?php endif ?>
          <a href="<?= e(url('/faq')) ?>"><?= icon('help') ?> FAQ</a>
          <a href="<?= e(url('/contact')) ?>"><?= icon('mail') ?> Contact</a>
          <button type="button" data-theme-toggle><?= icon('moon') ?> Toggle theme</button>
          <?php if (!$user): ?><div class="sep"></div><a href="<?= e(url('/login')) ?>"><?= icon('user') ?> Sign in</a><?php endif ?>
        </div>
      </details>
    </div>
  </div>
</header>
<?= $this->partial('partials/flash') ?>
<main id="main"><?= $content ?></main>
<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div>
        <a class="brand-row" href="<?= e(url('/')) ?>"><?= $this->partial('partials/brand') ?></a>
        <p class="text-muted mt-2"><?= e(setting('footer_text') ?: setting('site_tagline')) ?></p>
      </div>
      <div>
        <h4>Product</h4>
        <ul>
          <li><a href="<?= e(url('/services')) ?>">Services & pricing</a></li>
          <li><a href="<?= e(url('/api-docs')) ?>">Reseller API</a></li>
          <?php if (setting('blog_enabled', '1') === '1'): ?><li><a href="<?= e(url('/blog')) ?>">Blog</a></li><?php endif ?>
          <li><a href="<?= e(url('/faq')) ?>">FAQ</a></li>
        </ul>
      </div>
      <div>
        <h4>Company</h4>
        <ul>
          <li><a href="<?= e(url('/about')) ?>">About</a></li>
          <li><a href="<?= e(url('/contact')) ?>">Contact</a></li>
          <?php foreach ($footerPages as $fp): if (in_array($fp['slug'], ['about', 'terms', 'privacy', 'refund-policy'], true)) { continue; } ?>
          <li><a href="<?= e(url('/page/' . $fp['slug'])) ?>"><?= e($fp['title']) ?></a></li>
          <?php endforeach ?>
        </ul>
      </div>
      <div>
        <h4>Legal</h4>
        <ul>
          <li><a href="<?= e(url('/terms')) ?>">Terms of Service</a></li>
          <li><a href="<?= e(url('/privacy')) ?>">Privacy Policy</a></li>
          <li><a href="<?= e(url('/refund-policy')) ?>">Refund Policy</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; <?= date('Y') ?> <?= e(site_name()) ?>. All rights reserved.</span>
      <span class="flex gap-2 wrap">
        <?php foreach (['social_facebook' => 'Facebook', 'social_instagram' => 'Instagram', 'social_x' => 'X', 'social_youtube' => 'YouTube', 'social_telegram' => 'Telegram'] as $k => $label): if (setting($k)): ?>
          <a href="<?= e(setting($k)) ?>" rel="noopener" target="_blank"><?= e($label) ?></a>
        <?php endif; endforeach ?>
      </span>
    </div>
  </div>
</footer>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
