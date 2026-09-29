<?php
$user = auth_user();
$meta = \App\Services\SeoService::meta(['title' => $title ?? 'Dashboard', 'robots' => 'noindex,nofollow']);
$unread = \App\Services\NotificationService::unreadCount((int) $user['id']);
$ticketUnread = (int) db()->fetchColumn('SELECT COUNT(*) FROM tickets WHERE user_id = ? AND user_unread = 1', [$user['id']]);
$nav = [
    ['Overview', null],
    ['/dashboard', 'Dashboard', 'dashboard'],
    ['/order', 'New Order', 'plus'],
    ['/mass-order', 'Mass Order', 'layers', setting('mass_order_enabled', '1') === '1'],
    ['/orders', 'My Orders', 'list'],
    ['/refills', 'Refills', 'refresh', setting('refill_enabled', '1') === '1'],
    ['/catalog', 'Services', 'search'],
    ['Wallet', null],
    ['/funds', 'Add Funds', 'wallet'],
    ['/transactions', 'Transactions', 'receipt'],
    ['Support & growth', null],
    ['/tickets', 'Support Tickets', 'chat', true, $ticketUnread],
    ['/affiliates', 'Affiliates', 'gift', setting('referral_enabled', '1') === '1'],
    ['/account/api', 'API Access', 'code', setting('api_enabled', '1') === '1'],
    ['Account', null],
    ['/account', 'Profile', 'user'],
    ['/account/security', 'Security', 'shield'],
];
?>
<!doctype html>
<html lang="en">
<head><?= $this->partial('partials/head', ['meta' => $meta]) ?></head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="panel">
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <a class="sidebar-brand" href="<?= e(url('/dashboard')) ?>"><?= $this->partial('partials/brand') ?></a>
    <nav class="sidebar-nav">
      <?php foreach ($nav as $item):
          if ($item[1] === null) { echo '<div class="nav-section">' . e($item[0]) . '</div>'; continue; }
          if (isset($item[3]) && !$item[3]) { continue; }
          $active = $item[0] === '/account' ? is_active_path('/account') && !is_active_path('/account/security') && !is_active_path('/account/api') : is_active_path($item[0]); ?>
        <a class="nav-link <?= $active ? 'active' : '' ?>" href="<?= e(url($item[0])) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= icon($item[2]) ?> <?= e($item[1]) ?><?php if (!empty($item[4])): ?><span class="count"><?= (int) $item[4] ?></span><?php endif ?></a>
      <?php endforeach ?>
    </nav>
    <div class="sidebar-foot">
      <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="nav-link w-100" style="border:0;background:none;cursor:pointer" type="submit"><?= icon('logout') ?> Sign out</button></form>
    </div>
  </aside>
  <div class="backdrop"></div>
  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-toggle" type="button" data-nav-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open menu"><?= icon('menu') ?></button>
      <div class="topbar-title"><?= e($title ?? 'Dashboard') ?></div>
      <div class="spacer"></div>
      <a class="balance-chip" href="<?= e(url('/funds')) ?>" title="Add funds"><?= icon('wallet') ?> <span class="label hide-xs">Balance</span> <span><?= e(money($user['balance'])) ?></span></a>
      <button class="icon-btn hide-xs" type="button" data-theme-toggle aria-label="Toggle dark mode"><?= icon('moon') ?></button>
      <a class="icon-btn" href="<?= e(url('/notifications')) ?>" aria-label="Notifications"><?= icon('bell') ?><?php if ($unread): ?><span class="notif-dot"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif ?></a>
      <details class="dropdown">
        <summary class="user-chip" aria-label="Account menu"><span class="avatar"><?= e(mb_substr($user['username'], 0, 1)) ?></span></summary>
        <div class="dropdown-menu">
          <div class="dropdown-head"><strong style="color:var(--text)"><?= e($user['username']) ?></strong><br><?= e($user['email']) ?></div>
          <div class="sep"></div>
          <a href="<?= e(url('/account')) ?>"><?= icon('user') ?> Profile</a>
          <a href="<?= e(url('/account/security')) ?>"><?= icon('shield') ?> Security</a>
          <a href="<?= e(url('/transactions')) ?>"><?= icon('receipt') ?> Transactions</a>
          <button type="button" data-theme-toggle><?= icon('sun') ?> Toggle theme</button>
          <div class="sep"></div>
          <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button type="submit"><?= icon('logout') ?> Sign out</button></form>
        </div>
      </details>
    </header>
    <?= $this->partial('partials/flash') ?>
    <main class="content" id="main"><?= $content ?></main>
    <footer class="panel-footer">&copy; <?= date('Y') ?> <?= e(site_name()) ?> · <a href="<?= e(url('/terms')) ?>">Terms</a> · <a href="<?= e(url('/privacy')) ?>">Privacy</a> · <a href="<?= e(url('/api-docs')) ?>">API docs</a></footer>
  </div>
</div>
<nav class="bottom-nav" aria-label="Quick navigation">
  <a href="<?= e(url('/dashboard')) ?>" class="<?= is_active_path('/dashboard') ? 'active' : '' ?>"><?= icon('dashboard') ?>Home</a>
  <a href="<?= e(url('/orders')) ?>" class="<?= is_active_path('/orders') ? 'active' : '' ?>"><?= icon('list') ?>Orders</a>
  <a href="<?= e(url('/order')) ?>" class="primary-action <?= is_active_path('/order') ? 'active' : '' ?>"><?= icon('plus') ?>New</a>
  <a href="<?= e(url('/funds')) ?>" class="<?= is_active_path('/funds') ? 'active' : '' ?>"><?= icon('wallet') ?>Funds</a>
  <a href="#" data-nav-toggle><?= icon('menu') ?>More</a>
</nav>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
