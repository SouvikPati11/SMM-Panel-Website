<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>User balances</h1><p>Liabilities: funds held on behalf of customers.</p></div></div>
<div class="stats">
  <div class="stat"><div><div class="stat-label">Main balances</div><div class="stat-value"><?= e(money($totals['b'])) ?></div></div></div>
  <div class="stat"><div><div class="stat-label">Referral balances</div><div class="stat-value"><?= e(money($totals['r'])) ?></div></div></div>
  <div class="stat"><div><div class="stat-label">Lifetime deposits</div><div class="stat-value"><?= e(money($totals['d'])) ?></div></div></div>
  <div class="stat"><div><div class="stat-label">Lifetime spent</div><div class="stat-value"><?= e(money($totals['s'])) ?></div></div></div>
</div>
<div class="card"><div class="table-wrap"><table class="table table-cards">
  <thead><tr><th>User</th><th class="num">Balance</th><th class="num">Referral</th><th class="num">Deposits</th><th class="num">Spent</th><th>Updated</th><?php if (can('users.balance')): ?><th><span class="sr-only">Balance actions</span></th><?php endif ?></tr></thead>
  <tbody><?php foreach ($users->items as $u): ?>
    <tr><td class="cell-main"><a class="cell-title" href="<?= e(admin_url('users/' . $u['id'])) ?>"><?= e($u['username']) ?></a><div class="cell-sub"><?= e($u['email']) ?></div></td>
      <td data-label="Balance" class="num fw-bold"><?= e(money($u['balance'], 4)) ?></td><td data-label="Referral" class="num"><?= e(money($u['referral_balance'], 4)) ?></td>
      <td data-label="Deposits" class="num"><?= e(money($u['total_deposits'])) ?></td><td data-label="Spent" class="num"><?= e(money($u['total_spent'])) ?></td>
      <td data-label="Updated" class="text-sm"><?= e(time_ago($u['updated_at'])) ?></td><?php if (can('users.balance')): ?><td class="actions"><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('users/' . $u['id'] . '?balance=add')) ?>" title="Add balance" aria-label="Add balance to <?= e($u['username']) ?>"><?= icon('plus') ?></a><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('users/' . $u['id'] . '?balance=remove')) ?>" title="Remove balance" aria-label="Remove balance from <?= e($u['username']) ?>"><?= icon('minus') ?></a></td><?php endif ?></tr>
  <?php endforeach ?><?php if (!$users->items): ?><tr><td colspan="7" class="text-center text-muted">No balances.</td></tr><?php endif ?></tbody>
</table></div><?= $users->links(\App\Core\App::request()) ?></div>
