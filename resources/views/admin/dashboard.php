<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>Dashboard</h1><p>Last 30 days unless noted. All times <?= e(setting('timezone', 'UTC')) ?>.</p></div>
  <div class="btn-group"><?php if (can('orders.view')): ?><a class="btn btn-secondary" href="<?= e(admin_url('orders')) ?>">Orders</a><?php endif ?><?php if (can('payments.view') && $pendingManual): ?><a class="btn btn-primary" href="<?= e(admin_url('manual-payments')) ?>"><?= $pendingManual ?> payment<?= $pendingManual > 1 ? 's' : '' ?> to review</a><?php endif ?></div></div>

<?php if ($stats['orders_attention']): ?><div class="alert alert-warning"><?= icon('alert') ?><div><strong><?= $stats['orders_attention'] ?> order<?= $stats['orders_attention'] > 1 ? 's need' : ' needs' ?> review</strong> — provider response was ambiguous. <a href="<?= e(admin_url('orders?attention=1')) ?>">Resolve now</a>.</div></div><?php endif ?>
<?php $stale = array_filter($cron, fn ($c) => $c['stale']); if ($stale && can('system.manage')): ?><div class="alert alert-danger"><?= icon('clock') ?><div><strong>Cron jobs are not running</strong> (<?= e(implode(', ', array_keys($stale))) ?>). Orders won't sync and payments won't be verified. <a href="<?= e(admin_url('cron')) ?>">Set up cron</a>.</div></div><?php endif ?>

<div class="stats">
  <div class="stat"><div class="stat-icon"><?= icon('users') ?></div><div><div class="stat-label">Users</div><div class="stat-value"><?= number_format($stats['users']) ?></div><div class="stat-meta">+<?= number_format($stats['users_new']) ?> this month · <?= $stats['users_today'] ?> today</div></div></div>
  <div class="stat"><div class="stat-icon info"><?= icon('list') ?></div><div><div class="stat-label">Orders</div><div class="stat-value"><?= number_format($stats['orders']) ?></div><div class="stat-meta"><?= number_format($stats['orders_pending']) ?> pending · <?= number_format($stats['orders_active']) ?> active · <?= number_format($stats['orders_completed']) ?> done</div></div></div>
  <div class="stat"><div class="stat-icon success"><?= icon('trend') ?></div><div><div class="stat-label">Revenue (net of refunds)</div><div class="stat-value"><?= e(money($stats['revenue_30'])) ?></div><div class="stat-meta">Refunds <?= e(money($stats['refunds_30'])) ?> · profit* <?= e(money($stats['profit_30'])) ?></div></div></div>
  <div class="stat"><div class="stat-icon warning"><?= icon('wallet') ?></div><div><div class="stat-label">Deposits</div><div class="stat-value"><?= e(money($stats['deposits_30'])) ?></div><div class="stat-meta">Today <?= e(money($stats['deposits_today'])) ?> · user balances <?= e(money($stats['balance_total'])) ?></div></div></div>
</div>

<div class="grid-main">
  <div class="card"><div class="card-header"><h2>Last 14 days</h2></div><div class="card-body"><?= $this->partial('admin/partials/chart', ['chart' => $chart]) ?>
    <p class="text-xs text-muted mb-0 mt-1">*Profit counts only orders where the provider reported its charge.</p></div></div>
  <div>
    <div class="card mb-2"><div class="card-header"><h2>Providers</h2><?php if (can('providers.manage')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('providers')) ?>">Manage</a><?php endif ?></div>
      <?php if (!$providers): ?><div class="empty" style="padding:20px"><p class="mb-0">No providers yet.</p></div><?php else: ?>
      <ul class="list-plain list-rows"><?php foreach ($providers as $p): ?>
        <li><div style="min-width:0"><div class="cell-title"><?= e($p['name']) ?></div><div class="cell-sub"><?= $p['balance'] !== null ? 'Balance ' . e(\App\Core\Money::format($p['balance'])) . ' ' . e($p['currency']) : 'Balance unknown' ?></div></div><?= $p['status'] === 'disabled' ? status_badge('disabled') : status_badge($p['connection_status']) ?></li>
      <?php endforeach ?></ul><?php endif ?></div>
    <div class="card"><div class="card-header"><h2>Payment gateways</h2></div>
      <ul class="list-plain list-rows"><?php foreach ($gateways as $g): ?>
        <li><span><?= e($g['name']) ?></span><?php if (!$g['implemented']): ?><span class="badge badge-muted">Not implemented</span><?php elseif ($g['status'] !== 'active'): ?><span class="badge badge-muted">Disabled</span><?php elseif (!$g['configured']): ?><span class="badge badge-warning">Needs credentials</span><?php else: ?><span class="badge badge-success">Active</span><?php endif ?></li>
      <?php endforeach ?></ul></div>
  </div>
</div>

<div class="grid-2 mt-3">
  <div class="card"><div class="card-header"><h2>Recent transactions</h2><?php if (can('transactions.view')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('transactions')) ?>">All</a><?php endif ?></div>
    <ul class="list-plain list-rows"><?php foreach ($recentTx as $t): $pos = !\App\Core\Money::isNegative((string) $t['amount']); ?>
      <li><div style="min-width:0"><div class="cell-title"><?= e($t['username']) ?> · <?= e(str_replace('_', ' ', $t['type'])) ?></div><div class="cell-sub truncate"><?= e($t['description']) ?> · <?= e(time_ago($t['created_at'])) ?></div></div><strong class="nowrap <?= $pos ? 'text-success' : '' ?>"><?= $pos ? '+' : '' ?><?= e(money($t['amount'])) ?></strong></li>
    <?php endforeach ?><?php if (!$recentTx): ?><li class="text-muted">No transactions yet.</li><?php endif ?></ul></div>
  <div class="card"><div class="card-header"><h2>Recent tickets</h2><?php if (can('tickets.manage')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('tickets')) ?>">All</a><?php endif ?></div>
    <ul class="list-plain list-rows"><?php foreach ($recentTickets as $t): ?>
      <li><div style="min-width:0"><div class="cell-title truncate"><?php if (can('tickets.manage')): ?><a href="<?= e(admin_url('tickets/' . $t['id'])) ?>"><?= e($t['subject']) ?></a><?php else: ?><?= e($t['subject']) ?><?php endif ?></div><div class="cell-sub"><?= e($t['username']) ?> · <?= e(time_ago($t['last_reply_at'])) ?></div></div><?= status_badge($t['status']) ?></li>
    <?php endforeach ?><?php if (!$recentTickets): ?><li class="text-muted">No tickets yet.</li><?php endif ?></ul></div>
</div>
