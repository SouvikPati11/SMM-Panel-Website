<?php
$admin = auth_admin();
$meta = \App\Services\SeoService::meta(['title' => ($title ?? 'Admin') . ' · Admin', 'robots' => 'noindex,nofollow']);
$db = db();
$counts = [
    'tickets' => can('tickets.manage') ? (int) $db->fetchColumn('SELECT COUNT(*) FROM tickets WHERE admin_unread = 1 AND status <> ?', ['closed']) : 0,
    'manual' => can('payments.view') ? (int) $db->fetchColumn("SELECT COUNT(*) FROM manual_payment_requests WHERE status = 'pending'") : 0,
    'attention' => can('orders.view') ? (int) $db->fetchColumn('SELECT COUNT(*) FROM orders WHERE needs_attention = 1') : 0,
];
$nav = [
    ['Overview', null],
    ['', 'Dashboard', 'dashboard', 'dashboard.view'],
    ['Operations', null],
    ['orders', 'Orders', 'list', 'orders.view', $counts['attention']],
    ['subscriptions', 'Subscriptions', 'clock', 'orders.view'],
    ['refills', 'Refills', 'refresh', 'orders.view'],
    ['users', 'Users', 'users', 'users.view'],
    ['balances', 'User balances', 'wallet', 'users.view'],
    ['tickets', 'Tickets', 'chat', 'tickets.manage', $counts['tickets']],
    ['Catalog', null],
    ['services', 'Services', 'layers', 'services.manage'],
    ['categories', 'Categories', 'tag', 'services.manage'],
    ['providers', 'Providers', 'server', 'providers.manage'],
    ['Finance', null],
    ['payments', 'Payments', 'card', 'payments.view'],
    ['manual-payments', 'Manual payments', 'receipt', 'payments.view', $counts['manual']],
    ['transactions', 'Transactions', 'activity', 'transactions.view'],
    ['gateways', 'Payment gateways', 'wallet', 'gateways.manage'],
    ['Growth', null],
    ['coupons', 'Promo codes', 'gift', 'coupons.manage'],
    ['affiliates', 'Affiliates', 'users', 'affiliates.manage'],
    ['notifications', 'Notifications', 'megaphone', 'notifications.manage'],
    ['Content', null],
    ['pages', 'Pages', 'file', 'content.manage'],
    ['faq', 'FAQ', 'help', 'content.manage'],
    ['blog', 'Blog', 'book', 'content.manage'],
    ['seo', 'SEO', 'globe', 'seo.manage'],
    ['System', null],
    ['settings', 'Settings', 'settings', 'settings.manage'],
    ['settings/email', 'Email', 'mail', 'settings.manage'],
    ['price-levels', 'Price levels', 'trend', 'settings.manage'],
    ['currencies', 'Currencies', 'wallet', 'settings.manage'],
    ['admins', 'Admins & roles', 'shield', 'admins.manage'],
    ['logs', 'Logs', 'file', 'logs.view'],
    ['health', 'System health', 'activity', 'system.manage'],
    ['cron', 'Cron tasks', 'clock', 'system.manage'],
];
$base = '/' . admin_path();
$sections = [];
$current = null;
foreach ($nav as $item) {
    if ($item[1] === null) { $current = $item[0]; $sections[$current] = []; continue; }
    if (!can($item[3])) { continue; }
    $sections[$current][] = $item;
}
?>
<!doctype html>
<html lang="en">
<head><?= $this->partial('partials/head', ['meta' => $meta]) ?></head>
<body class="admin">
<a class="skip-link" href="#main">Skip to content</a>
<div class="panel">
  <aside class="sidebar" id="sidebar" aria-label="Admin navigation">
    <a class="sidebar-brand" href="<?= e(admin_url()) ?>"><?= $this->partial('partials/brand') ?> <span class="badge badge-primary no-dot" style="margin-left:auto">Admin</span></a>
    <nav class="sidebar-nav">
      <?php foreach ($sections as $label => $items): if (!$items) { continue; } ?>
        <div class="nav-section"><?= e($label) ?></div>
        <?php foreach ($items as $item):
            $path = $base . ($item[0] !== '' ? '/' . $item[0] : '');
            $active = $item[0] === '' ? (\App\Core\App::request()?->path() === $base) : (is_active_path($path) && !($item[0] === 'settings' && is_active_path($base . '/settings/email'))); ?>
          <a class="nav-link <?= $active ? 'active' : '' ?>" href="<?= e(url($path)) ?>"><?= icon($item[2]) ?> <?= e($item[1]) ?><?php if (!empty($item[4])): ?><span class="count"><?= (int) $item[4] ?></span><?php endif ?></a>
        <?php endforeach ?>
      <?php endforeach ?>
    </nav>
  </aside>
  <div class="backdrop"></div>
  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-toggle" type="button" data-nav-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open menu"><?= icon('menu') ?></button>
      <div class="topbar-title"><?= e($title ?? 'Admin') ?></div>
      <div class="spacer"></div>
      <a class="btn btn-ghost btn-sm hide-mobile" href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= icon('external') ?> View site</a>
      <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle dark mode"><?= icon('moon') ?></button>
      <details class="dropdown">
        <summary class="user-chip" aria-label="Admin menu"><span class="avatar"><?= e(mb_substr($admin['username'], 0, 1)) ?></span></summary>
        <div class="dropdown-menu">
          <div class="dropdown-head"><strong style="color:var(--text)"><?= e($admin['name'] ?: $admin['username']) ?></strong><br><?= e($admin['email']) ?></div>
          <div class="sep"></div>
          <a href="<?= e(admin_url('account')) ?>"><?= icon('shield') ?> My account & 2FA</a>
          <div class="sep"></div>
          <form method="post" action="<?= e(admin_url('logout')) ?>"><?= csrf_field() ?><button type="submit"><?= icon('logout') ?> Sign out</button></form>
        </div>
      </details>
    </header>
    <?= $this->partial('partials/flash') ?>
    <main class="content" id="main"><?= $content ?></main>
  </div>
</div>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
