<?php $this->extend('layouts/user');
$extra = json_decode((string) $order['extra'], true) ?: [];
$active = in_array($order['status'], ['pending', 'processing', 'in_progress'], true);
$canCancel = $active && setting('order_cancel_enabled', '1') === '1' && ((int) $order['can_cancel'] === 1 || in_array($order['submit_state'], ['queued', 'manual'], true)) && !(int) $order['cancel_requested'];
$canRefill = (int) $order['can_refill'] === 1 && in_array($order['status'], ['completed', 'partial'], true) && setting('refill_enabled', '1') === '1';
$total = (int) $order['quantity'] * max(1, (int) $order['runs']);
$delivered = $order['remains'] !== null ? max(0, $total - (int) $order['remains']) : ($order['status'] === 'completed' ? $total : null);
$eventLabels = ['created' => 'Order placed', 'submitted' => 'Sent for processing', 'status' => 'Status updated', 'refund_partial' => 'Partial refund issued', 'refund_cancel' => 'Refunded (cancelled)', 'refund_fail' => 'Refunded (failed)', 'refund_admin' => 'Refunded by support', 'refill_requested' => 'Refill requested', 'cancel_requested' => 'Cancellation requested'];
?>
<div class="page-head">
  <div>
    <ol class="breadcrumb"><li><a href="<?= e(url('/orders')) ?>">Orders</a></li><li>#<?= (int) $order['id'] ?></li></ol>
    <h1>Order #<?= (int) $order['id'] ?> <?= status_badge($order['status']) ?></h1>
  </div>
  <div class="btn-group">
    <?php if ($canRefill): ?><form method="post" action="<?= e(url('/orders/' . $order['id'] . '/refill')) ?>"><?= csrf_field() ?><button class="btn btn-soft" type="submit"><?= icon('refresh') ?> Request refill</button></form><?php endif ?>
    <?php if ($canCancel): ?><form method="post" action="<?= e(url('/orders/' . $order['id'] . '/cancel')) ?>" data-confirm="Cancel this order? Undelivered quantity will be refunded."><?= csrf_field() ?><button class="btn btn-secondary" type="submit"><?= icon('x-circle') ?> Cancel</button></form><?php endif ?>
    <a class="btn btn-ghost" href="<?= e(url('/tickets/new?category=order&order=' . $order['id'])) ?>"><?= icon('chat') ?> Get help</a>
  </div>
</div>

<div class="grid-main">
  <div>
    <div class="card mb-2"><div class="card-body">
      <dl class="dl">
        <dt>Service</dt><dd><strong><?= e($order['service']) ?></strong> <span class="text-muted">(ID <?= (int) $order['service_id'] ?>)</span></dd>
        <dt><?= e($order['link_label'] ?: 'Link') ?></dt><dd class="break"><?= link_html($order['link']) ?></dd>
        <dt>Quantity</dt><dd><?= number_format((int) $order['quantity']) ?><?php if ($order['runs']): ?> × <?= (int) $order['runs'] ?> runs every <?= (int) $order['interval'] ?> min<?php endif ?></dd>
        <dt>Rate</dt><dd><?= e(rate($order['rate'])) ?> <?= (\App\Services\OrderService::TYPES[$order['service_type']]['package'] ?? false) ? 'per package' : 'per 1000' ?></dd>
        <dt>Charge</dt><dd><strong><?= e(money($order['charge'])) ?></strong><?php if (\App\Core\Money::isPositive((string) $order['refunded_amount'])): ?> <span class="text-success">(<?= e(money($order['refunded_amount'])) ?> refunded)</span><?php endif ?></dd>
        <dt>Start count</dt><dd><?= $order['start_count'] !== null ? number_format((int) $order['start_count']) : '—' ?></dd>
        <dt>Remains</dt><dd><?= $order['remains'] !== null ? number_format((int) $order['remains']) : '—' ?></dd>
        <dt>Created</dt><dd><?= e(fmt_date($order['created_at'])) ?></dd>
        <?php if ($order['completed_at']): ?><dt>Finished</dt><dd><?= e(fmt_date($order['completed_at'])) ?></dd><?php endif ?>
        <?php foreach ($extra as $k => $v): ?><dt><?= e(ucwords(str_replace('_', ' ', $k))) ?></dt><dd style="white-space:pre-wrap"><?= e($v) ?></dd><?php endforeach ?>
      </dl>
      <?php if ($delivered !== null && $total > 0): ?>
        <div class="mt-2"><div class="flex justify-between text-sm mb-1"><span>Delivered</span><span><?= number_format($delivered) ?> / <?= number_format($total) ?></span></div><div class="progress"><span style="width:<?= min(100, (int) round($delivered / $total * 100)) ?>%"></span></div></div>
      <?php endif ?>
      <?php if ((int) $order['cancel_requested'] === 1 && $active): ?><div class="alert alert-info mt-2 mb-0"><?= icon('info') ?><div>Cancellation requested — the refund will be applied as soon as the provider confirms.</div></div><?php endif ?>
    </div></div>

    <?php if ($refills): ?>
    <div class="card"><div class="card-header"><h2>Refills</h2></div>
      <ul class="list-plain list-rows"><?php foreach ($refills as $r): ?><li><span>Refill #<?= (int) $r['id'] ?> · <?= e(fmt_date($r['created_at'])) ?></span><?= status_badge($r['status']) ?></li><?php endforeach ?></ul>
    </div>
    <?php endif ?>
  </div>
  <div class="card"><div class="card-header"><h2>Timeline</h2></div><div class="card-body">
    <ul class="timeline">
      <?php foreach ($logs as $l): ?>
      <li><div class="fw-bold"><?= e($eventLabels[$l['event']] ?? ucfirst($l['event'])) ?><?= $l['event'] === 'status' && $l['new_status'] ? ': ' . e(str_replace('_', ' ', $l['new_status'])) : '' ?></div><div class="text-muted text-xs"><?= e(fmt_date($l['created_at'])) ?></div></li>
      <?php endforeach ?>
    </ul>
  </div></div>
</div>
