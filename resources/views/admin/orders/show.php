<?php $this->extend('layouts/admin'); use App\Helpers\Form; use App\Core\Money;
$base = admin_url('orders/' . $order['id']); $final = in_array($order['status'], \App\Services\OrderService::FINAL_STATUSES, true);
$extra = json_decode((string) $order['extra'], true) ?: []; ?>
<div class="page-head">
  <div><ol class="breadcrumb"><li><a href="<?= e(admin_url('orders')) ?>">Orders</a></li><li>#<?= (int) $order['id'] ?></li></ol>
    <h1>Order #<?= (int) $order['id'] ?> <?= status_badge($order['status']) ?></h1>
    <p>by <a href="<?= e(admin_url('users/' . $order['user_id'])) ?>"><?= e($order['username']) ?></a> · <?= e(fmt_date($order['created_at'])) ?> · via <?= e($order['source']) ?></p></div>
  <?php if (can('orders.manage') && $order['provider_order_id'] && !$final): ?><form method="post" action="<?= e($base . '/sync') ?>"><?= csrf_field() ?><button class="btn btn-secondary" type="submit"><?= icon('refresh') ?> Sync from provider</button></form><?php endif ?>
</div>

<?php if (in_array($order['submit_state'], ['unknown', 'submitting'], true) && can('orders.manage')): ?>
<div class="card mb-2" style="border-color:var(--warning)"><div class="card-body">
  <div class="alert alert-warning"><?= icon('alert') ?><div><div class="alert-title">Submission outcome unknown</div>The provider did not give a clear answer (<?= e($order['last_error']) ?>). The customer was charged and nothing was retried or refunded automatically. Check the provider's panel for this link, then resolve:</div></div>
  <div class="grid-3">
    <form method="post" action="<?= e($base . '/resolve') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="submitted"><div class="field"><label>Provider accepted it — order ID:</label><input class="input" name="provider_order_id" required></div><button class="btn btn-success btn-block" type="submit">Mark submitted</button></form>
    <form method="post" action="<?= e($base . '/resolve') ?>" data-confirm="Only do this if the provider definitely did NOT receive the order. Resubmit now?"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><p class="text-sm">Provider has no such order:</p><button class="btn btn-secondary btn-block" type="submit">Resubmit to provider</button></form>
    <form method="post" action="<?= e($base . '/resolve') ?>" data-confirm="Fail the order and refund the customer in full?"><?= csrf_field() ?><input type="hidden" name="action" value="fail"><p class="text-sm">Give up on this order:</p><button class="btn btn-danger btn-block" type="submit">Fail & refund</button></form>
  </div>
</div></div>
<?php endif ?>

<div class="grid-main">
  <div>
    <div class="card mb-2"><div class="card-body"><dl class="dl">
      <dt>Service</dt><dd><?= e($order['service']) ?> <span class="text-muted">(#<?= (int) $order['service_id'] ?>, <?= e($order['service_type']) ?>)</span></dd>
      <dt>Link</dt><dd class="break"><?= link_html($order['link']) ?></dd>
      <dt>Quantity</dt><dd><?= number_format((int) $order['quantity']) ?><?= $order['runs'] ? ' × ' . (int) $order['runs'] . ' runs / ' . (int) $order['interval'] . ' min' : '' ?></dd>
      <?php foreach ($extra as $k => $v): ?><dt><?= e(ucwords(str_replace('_', ' ', $k))) ?></dt><dd style="white-space:pre-wrap"><?= e($v) ?></dd><?php endforeach ?>
      <dt>Rate / charge</dt><dd><?= e(rate($order['rate'])) ?> → <strong><?= e(money($order['charge'], 4)) ?></strong><?= Money::isPositive((string) $order['refunded_amount']) ? ' · refunded ' . e(money($order['refunded_amount'], 4)) : '' ?></dd>
      <dt>Provider cost</dt><dd><?= $order['cost'] !== null ? e(Money::formatRate($order['cost'])) : '—' ?></dd>
      <dt>Provider</dt><dd><?= e($order['provider'] ?: 'Manual fulfilment') ?><?= $order['provider_service_id'] ? ' · service ' . e($order['provider_service_id']) : '' ?></dd>
      <dt>Provider order ID</dt><dd class="mono"><?= e($order['provider_order_id'] ?: '—') ?></dd>
      <dt>Submission</dt><dd><?= e($order['submit_state']) ?> (<?= (int) $order['submit_attempts'] ?> attempts)<?= $order['last_error'] ? '<div class="text-danger text-sm">' . e($order['last_error']) . '</div>' : '' ?></dd>
      <dt>Start / remains</dt><dd><?= $order['start_count'] ?? '—' ?> / <?= $order['remains'] ?? '—' ?></dd>
      <dt>Last synced</dt><dd><?= e(fmt_date($order['last_synced_at'])) ?></dd>
    </dl></div></div>
    <div class="card mb-2"><div class="card-header"><h2>Ledger entries</h2></div><div class="table-wrap"><table class="table">
      <thead><tr><th>Tx</th><th>Type</th><th class="num">Amount</th><th>Reference</th><th>Date</th></tr></thead><tbody>
      <?php foreach ($tx as $t): ?><tr><td class="mono">#<?= (int) $t['id'] ?></td><td><?= e($t['type']) ?></td><td class="num"><?= e(money($t['amount'], 4)) ?></td><td class="mono text-xs"><?= e($t['reference']) ?></td><td class="text-sm"><?= e(fmt_date($t['created_at'])) ?></td></tr><?php endforeach ?>
    </tbody></table></div></div>
    <div class="card"><div class="card-header"><h2>Order log</h2></div><div class="card-body"><ul class="timeline">
      <?php foreach ($logs as $l): ?><li><div><strong><?= e($l['event']) ?></strong><?= $l['new_status'] && $l['new_status'] !== $l['old_status'] ? ' → ' . e($l['new_status']) : '' ?> <span class="text-muted text-xs">by <?= e($l['actor']) ?></span></div><?php if ($l['message']): ?><div class="text-sm"><?= e($l['message']) ?></div><?php endif ?><div class="text-xs text-muted"><?= e(fmt_date($l['created_at'], 'M j, Y H:i:s')) ?></div></li><?php endforeach ?>
    </ul></div></div>
  </div>
  <div>
    <?php $next = \App\Services\OrderService::adminAllowedStatuses($order); if (can('orders.manage') && !$final && $next): $refundable = Money::sub((string) $order['charge'], (string) $order['refunded_amount']);
      $labels = ['processing' => 'Processing — no balance change', 'in_progress' => 'In progress — no balance change', 'completed' => 'Completed — no balance change', 'partial' => 'Partial — refunds the undelivered part (enter remains)', 'cancelled' => 'Cancelled — refunds ' . money($refundable, 4)]; ?>
    <div class="card mb-2"><div class="card-header"><h2>Change status</h2><?= status_badge($order['status']) ?></div><div class="card-body">
      <form method="post" action="<?= e($base . '/status') ?>" data-confirm="Apply this status change? The balance effect is shown next to each status."><?= csrf_field() ?>
        <?= Form::select('status', 'New status', array_intersect_key($labels, array_flip($next)), $next[0]) ?>
        <div class="form-grid"><?= Form::input('start_count', 'Start count', $order['start_count'], ['type' => 'number', 'min' => 0]) ?><?= Form::input('remains', 'Remains', $order['remains'], ['type' => 'number', 'min' => 0, 'max' => (int) $order['quantity'] * max(1, (int) $order['runs'])]) ?></div>
        <?= Form::input('reason', 'Reason', '', ['required' => true, 'maxlength' => 400, 'attrs' => ['minlength' => 3], 'hint' => 'Recorded with your name in the order log and audit log.']) ?>
        <button class="btn btn-primary btn-block" type="submit">Update status</button>
      </form>
      <p class="hint mb-0">Only valid next statuses are listed. Final orders cannot be re-opened; a completed order can only be refunded below.</p>
    </div></div>
    <?php endif ?>
    <?php if (can('orders.manage') && !in_array($order['status'], ['refunded', 'failed'], true) && Money::isPositive(Money::sub((string) $order['charge'], (string) $order['refunded_amount']))): ?>
    <div class="card mb-2"><div class="card-header"><h2>Refund</h2></div><div class="card-body">
      <p class="text-sm">Refunds the remaining <?= e(money(Money::sub((string) $order['charge'], (string) $order['refunded_amount']), 4)) ?> to the user's balance and marks the order refunded. Does not cancel at the provider.</p>
      <form method="post" action="<?= e($base . '/refund') ?>" data-confirm="Refund this order?"><?= csrf_field() ?><?= Form::input('reason', 'Reason', '', ['required' => true]) ?><button class="btn btn-danger btn-block" type="submit">Refund</button></form>
    </div></div>
    <?php endif ?>
    <?php if ($refills): ?><div class="card"><div class="card-header"><h2>Refills</h2></div><ul class="list-plain list-rows"><?php foreach ($refills as $r): ?><li><span>#<?= (int) $r['id'] ?> <?= e($r['provider_refill_id'] ? '(prov ' . $r['provider_refill_id'] . ')' : '') ?></span><?= status_badge($r['status']) ?></li><?php endforeach ?></ul></div><?php endif ?>
  </div>
</div>
