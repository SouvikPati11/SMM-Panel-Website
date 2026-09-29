<?php $this->extend('layouts/user'); use App\Core\Money; ?>
<div class="page-head">
  <div>
    <h1>Hi, <?= e($user['name'] ?: $user['username']) ?> 👋</h1>
    <p>Here's what's happening with your account.</p>
  </div>
  <div class="btn-group">
    <a class="btn btn-secondary" href="<?= e(url('/funds')) ?>"><?= icon('wallet') ?> Add funds</a>
    <a class="btn btn-primary" href="<?= e(url('/order')) ?>"><?= icon('plus') ?> New order</a>
  </div>
</div>

<?php foreach ($announcements as $a): ?>
<div class="card announcement <?= e($a['level']) ?> mb-2"><div class="card-body flex gap-2">
  <span class="stat-icon <?= e($a['level']) ?>"><?= icon('megaphone') ?></span>
  <div><div class="card-title"><?= e($a['title']) ?></div><div class="text-sm" style="color:var(--text-2)"><?= \App\Core\HtmlSanitizer::clean($a['body']) ?></div></div>
</div></div>
<?php endforeach ?>

<div class="stats">
  <div class="stat balance-card">
    <div class="stat-icon"><?= icon('wallet') ?></div>
    <div><div class="stat-label">Balance</div><div class="stat-value"><?= e(money($user['balance'])) ?></div><div class="stat-meta"><a href="<?= e(url('/funds')) ?>" style="color:#fff;text-decoration:underline">Top up</a><?php if (Money::isPositive($discount)): ?> · <?= e(rtrim(rtrim($discount, '0'), '.')) ?>% discount<?php endif ?></div></div>
  </div>
  <div class="stat"><div class="stat-icon info"><?= icon('list') ?></div><div><div class="stat-label">Total orders</div><div class="stat-value"><?= number_format($stats['total']) ?></div><div class="stat-meta"><?= e(money($user['total_spent'])) ?> spent</div></div></div>
  <div class="stat"><div class="stat-icon success"><?= icon('check-circle') ?></div><div><div class="stat-label">Completed</div><div class="stat-value"><?= number_format($stats['completed']) ?></div><div class="stat-meta"><?= number_format($stats['partial']) ?> partial</div></div></div>
  <div class="stat"><div class="stat-icon warning"><?= icon('clock') ?></div><div><div class="stat-label">Active</div><div class="stat-value"><?= number_format($stats['pending'] + $stats['processing']) ?></div><div class="stat-meta"><?= number_format($stats['pending']) ?> pending · <?= number_format($stats['processing']) ?> processing</div></div></div>
</div>

<div class="grid-main">
  <div class="card">
    <div class="card-header"><h2>Recent orders</h2><a class="btn btn-ghost btn-sm" href="<?= e(url('/orders')) ?>">View all <?= icon('arrow-right') ?></a></div>
    <?php if (!$recentOrders): ?>
      <div class="empty"><?= icon('cart') ?><h3>No orders yet</h3><p>Place your first order in under a minute.</p><a class="btn btn-primary mt-1" href="<?= e(url('/order')) ?>">New order</a></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
      <thead><tr><th>ID</th><th>Service</th><th class="num">Charge</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentOrders as $o): ?>
        <tr>
          <td data-label="ID"><a class="mono" href="<?= e(url('/orders/' . $o['id'])) ?>">#<?= (int) $o['id'] ?></a></td>
          <td class="cell-main"><a class="cell-title" href="<?= e(url('/orders/' . $o['id'])) ?>" style="color:var(--text)"><?= e(str_limit($o['service'], 60)) ?></a><div class="cell-sub truncate" style="max-width:320px"><?= e($o['link']) ?> · <?= number_format((int) $o['quantity']) ?></div></td>
          <td data-label="Charge" class="num"><?= e(money($o['charge'])) ?></td>
          <td data-label="Status"><?= status_badge($o['status']) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
    <?php endif ?>
  </div>

  <div>
    <div class="card mb-2">
      <div class="card-header"><h2>Quick actions</h2></div>
      <div class="card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <a class="btn btn-soft" href="<?= e(url('/order')) ?>"><?= icon('plus') ?> Order</a>
        <a class="btn btn-soft" href="<?= e(url('/funds')) ?>"><?= icon('wallet') ?> Funds</a>
        <a class="btn btn-soft" href="<?= e(url('/tickets/new')) ?>"><?= icon('chat') ?> Support</a>
        <a class="btn btn-soft" href="<?= e(url('/catalog')) ?>"><?= icon('search') ?> Services</a>
      </div>
      <?php if ($openTickets): ?><div class="card-footer text-sm"><?= icon('chat') ?> You have <a href="<?= e(url('/tickets')) ?>"><?= $openTickets ?> open ticket<?= $openTickets > 1 ? 's' : '' ?></a>.</div><?php endif ?>
    </div>
    <div class="card">
      <div class="card-header"><h2>Recent transactions</h2><a class="btn btn-ghost btn-sm" href="<?= e(url('/transactions')) ?>">All</a></div>
      <?php if (!$recentTx): ?><div class="empty" style="padding:28px"><p class="mb-0">No transactions yet.</p></div><?php else: ?>
      <ul class="list-plain list-rows">
        <?php foreach ($recentTx as $t): $pos = !Money::isNegative((string) $t['amount']); ?>
        <li><div style="min-width:0"><div class="cell-title truncate"><?= e(ucwords(str_replace('_', ' ', $t['type']))) ?></div><div class="cell-sub"><?= e(time_ago($t['created_at'])) ?></div></div>
          <strong class="<?= $pos ? 'text-success' : '' ?> nowrap"><?= $pos ? '+' : '' ?><?= e(money($t['amount'])) ?></strong></li>
        <?php endforeach ?>
      </ul>
      <?php endif ?>
    </div>
  </div>
</div>
