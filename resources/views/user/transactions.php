<?php $this->extend('layouts/user'); use App\Core\Money;
$types = ['' => 'All', 'deposit' => 'Deposits', 'order_charge' => 'Orders', 'refund' => 'Refunds', 'bonus' => 'Bonuses', 'affiliate_commission' => 'Affiliate', 'manual_adjustment' => 'Adjustments']; ?>
<div class="page-head">
  <div><h1>Transactions</h1><p>Every change to your balance, with the balance after each entry.</p></div>
  <div class="stats" style="grid-template-columns:repeat(2,minmax(0,1fr));margin:0;min-width:min(420px,100%)">
    <div class="stat" style="padding:12px"><div><div class="stat-label">Total deposited</div><div class="stat-value" style="font-size:1.1rem"><?= e(money($user['total_deposits'])) ?></div></div></div>
    <div class="stat" style="padding:12px"><div><div class="stat-label">Total spent</div><div class="stat-value" style="font-size:1.1rem"><?= e(money($user['total_spent'])) ?></div></div></div>
  </div>
</div>
<div class="chips"><?php foreach ($types as $k => $l): ?><a class="chip <?= $type === $k ? 'active' : '' ?>" href="<?= e(url('/transactions' . ($k ? '?type=' . $k : ''))) ?>"><?= e($l) ?></a><?php endforeach ?></div>
<div class="card">
  <?php if (!$tx->items): ?><div class="empty"><?= icon('receipt') ?><h3>No transactions</h3></div><?php else: ?>
  <div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>ID</th><th>Type</th><th>Description</th><th class="num">Amount</th><th class="num">Balance after</th><th>Date</th></tr></thead>
    <tbody><?php foreach ($tx->items as $t): $pos = !Money::isNegative((string) $t['amount']); ?>
      <tr>
        <td data-label="ID" class="mono">#<?= (int) $t['id'] ?></td>
        <td data-label="Type"><span class="badge <?= $pos ? 'badge-success' : 'badge-muted' ?> no-dot"><?= e(ucwords(str_replace('_', ' ', $t['type']))) ?></span><?= $t['wallet'] === 'referral' ? ' <span class="text-xs text-muted">referral wallet</span>' : '' ?></td>
        <td class="cell-main"><?= e($t['description']) ?><?php if ($t['order_id']): ?> · <a href="<?= e(url('/orders/' . $t['order_id'])) ?>">view order</a><?php endif ?></td>
        <td data-label="Amount" class="num fw-bold nowrap <?= $pos ? 'text-success' : '' ?>"><?= $pos ? '+' : '' ?><?= e(money($t['amount'], 4)) ?></td>
        <td data-label="Balance after" class="num nowrap"><?= e(money($t['balance_after'], 4)) ?></td>
        <td data-label="Date" class="text-sm nowrap"><?= e(fmt_date($t['created_at'])) ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= $tx->links(\App\Core\App::request()) ?>
  <?php endif ?>
</div>
