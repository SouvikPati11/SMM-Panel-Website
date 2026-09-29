<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>Payments</h1><p>Completed in the last 30 days: <?= e(money($sum['total'])) ?> (<?= (int) $sum['n'] ?>)</p></div><a class="btn btn-secondary" href="<?= e(admin_url('manual-payments')) ?>">Manual payments</a></div>
<div class="chips"><?php foreach (['' => 'All', 'pending' => 'Pending', 'completed' => 'Completed', 'failed' => 'Failed', 'expired' => 'Expired'] as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(admin_url('payments' . ($k ? '?status=' . $k : ''))) ?>"><?= $l ?></a><?php endforeach ?></div>
<form class="toolbar" method="get" action="<?= e(admin_url('payments')) ?>"><?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
  <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Payment ID, gateway reference or username">
  <select class="select" name="gateway" data-autosubmit><option value="">All gateways</option><?php foreach (['oxapay' => 'OxaPay', 'cryptomus' => 'Cryptomus', 'manual' => 'Manual', 'p2gateway' => 'P2Gateway'] as $k => $l): ?><option value="<?= $k ?>"<?= $gateway === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?></select>
  <button class="btn btn-secondary" type="submit"><?= icon('search') ?></button></form>
<div class="card"><div class="table-wrap"><table class="table table-cards">
  <thead><tr><th>ID</th><th>User</th><th>Method</th><th class="num">Amount</th><th class="num">Bonus</th><th>Gateway ref</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>
  <?php foreach ($payments->items as $p): $meta = json_decode((string) $p['meta'], true) ?: []; ?>
    <tr><td data-label="ID" class="mono">#<?= (int) $p['id'] ?></td><td data-label="User"><a href="<?= e(admin_url('users/' . $p['user_id'])) ?>"><?= e($p['username']) ?></a></td>
      <td class="cell-main"><?= e($p['method'] ?? \App\Services\PaymentService::gatewayLabel($p['gateway'])) ?><?php if (!empty($meta['review'])): ?><div class="text-xs text-danger">Review: <?= e($meta['review']) ?></div><?php endif ?><?php if (!empty($meta['error'])): ?><div class="text-xs text-danger"><?= e($meta['error']) ?></div><?php endif ?></td>
      <td data-label="Amount" class="num fw-bold nowrap"><?= e(money($p['amount'])) ?><?= (float) $p['fee'] > 0 ? '<div class="cell-sub">+' . e(money($p['fee'])) . ' fee</div>' : '' ?></td>
      <td data-label="Bonus" class="num"><?= (float) $p['bonus_amount'] > 0 ? e(money($p['bonus_amount'])) : '—' ?></td>
      <td data-label="Ref" class="mono text-xs break" style="max-width:180px"><?= e($p['gateway_ref'] ?: '—') ?><?= $p['gateway_status'] ? '<div class="cell-sub">' . e($p['gateway_status']) . '</div>' : '' ?></td>
      <td data-label="Status"><?= status_badge($p['status']) ?></td><td data-label="Created" class="text-sm nowrap"><?= e(fmt_date($p['created_at'])) ?></td>
      <td class="actions"><?php if (can('payments.manage') && $p['status'] !== 'completed' && in_array($p['gateway'], ['oxapay', 'cryptomus'], true) && $p['gateway_ref']): ?><form class="inline-form" method="post" action="<?= e(admin_url('payments/' . $p['id'] . '/verify')) ?>"><?= csrf_field() ?><button class="btn btn-soft btn-sm" type="submit" title="Query the gateway API">Verify</button></form><?php endif ?></td></tr>
  <?php endforeach ?><?php if (!$payments->items): ?><tr><td colspan="9" class="text-center text-muted" style="padding:28px">No payments.</td></tr><?php endif ?>
</tbody></table></div><?= $payments->links(\App\Core\App::request()) ?></div>
