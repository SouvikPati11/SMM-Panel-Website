<?php $this->extend('layouts/admin'); use App\Core\Money; ?>
<div class="page-head"><div><h1>Transactions</h1><p>The immutable wallet ledger.</p></div></div>
<form class="toolbar" method="get" action="<?= e(admin_url('transactions')) ?>">
  <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Username, reference or ID">
  <select class="select" name="type"><option value="">All types</option><?php foreach (\App\Services\WalletService::TYPES as $t): ?><option value="<?= $t ?>"<?= $type === $t ? ' selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $t))) ?></option><?php endforeach ?></select>
  <input class="input" type="date" name="from" value="<?= e($from) ?>" style="max-width:170px" aria-label="From"><input class="input" type="date" name="to" value="<?= e($to) ?>" style="max-width:170px" aria-label="To">
  <button class="btn btn-secondary" type="submit"><?= icon('filter') ?> Filter</button></form>
<?php if ($totals): ?><div class="chips"><?php foreach ($totals as $t => $sum): ?><span class="chip"><?= e(ucwords(str_replace('_', ' ', $t))) ?>: <strong><?= e(money($sum)) ?></strong></span><?php endforeach ?></div><?php endif ?>
<div class="card"><div class="table-wrap"><table class="table table-cards">
  <thead><tr><th>ID</th><th>User</th><th>Type</th><th>Description</th><th class="num">Amount</th><th class="num">Before → after</th><th>Reference</th><th>Date</th></tr></thead><tbody>
  <?php foreach ($tx->items as $t): $pos = !Money::isNegative((string) $t['amount']); ?>
    <tr><td data-label="ID" class="mono">#<?= (int) $t['id'] ?></td><td data-label="User"><a href="<?= e(admin_url('users/' . $t['user_id'])) ?>"><?= e($t['username']) ?></a></td>
      <td data-label="Type"><?= e(str_replace('_', ' ', $t['type'])) ?><?= $t['wallet'] === 'referral' ? ' <span class="text-xs text-muted">(ref)</span>' : '' ?></td>
      <td class="cell-main text-sm"><?= e($t['description']) ?><?= $t['order_id'] ? ' · <a href="' . e(admin_url('orders/' . $t['order_id'])) . '">order</a>' : '' ?><?= $t['admin_id'] ? ' <span class="badge badge-purple no-dot">admin #' . (int) $t['admin_id'] . '</span>' : '' ?></td>
      <td data-label="Amount" class="num fw-bold nowrap <?= $pos ? 'text-success' : '' ?>"><?= $pos ? '+' : '' ?><?= e(money($t['amount'], 4)) ?></td>
      <td data-label="Balance" class="num text-sm nowrap"><?= e(Money::format($t['balance_before'], 4)) ?> → <?= e(Money::format($t['balance_after'], 4)) ?></td>
      <td data-label="Reference" class="mono text-xs"><?= e($t['reference'] ?? '') ?></td><td data-label="Date" class="text-sm nowrap"><?= e(fmt_date($t['created_at'])) ?></td></tr>
  <?php endforeach ?><?php if (!$tx->items): ?><tr><td colspan="8" class="text-center text-muted" style="padding:28px">No transactions.</td></tr><?php endif ?>
</tbody></table></div><?= $tx->links(\App\Core\App::request()) ?></div>
