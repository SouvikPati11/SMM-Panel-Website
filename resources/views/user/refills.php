<?php $this->extend('layouts/user'); ?>
<div class="page-head"><div><h1>Refills</h1><p>Refill requests for your orders. Request a refill from any eligible order's page.</p></div></div>
<div class="card">
  <?php if (!$refills->items): ?>
    <div class="empty"><?= icon('refresh') ?><h3>No refill requests</h3><p>Services marked "Refill" can be refilled within their refill period.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>Refill</th><th>Order</th><th>Service</th><th>Status</th><th>Requested</th></tr></thead>
    <tbody><?php foreach ($refills->items as $r): ?>
      <tr><td data-label="Refill" class="mono">#<?= (int) $r['id'] ?></td>
        <td data-label="Order"><a href="<?= e(url('/orders/' . $r['order_id'])) ?>">#<?= (int) $r['order_id'] ?></a></td>
        <td class="cell-main"><span class="cell-title"><?= e(str_limit($r['service'], 70)) ?></span><div class="cell-sub truncate"><?= e($r['link']) ?></div></td>
        <td data-label="Status"><?= status_badge($r['status']) ?></td>
        <td data-label="Requested" class="text-sm nowrap"><?= e(fmt_date($r['created_at'])) ?></td></tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= $refills->links(\App\Core\App::request()) ?>
  <?php endif ?>
</div>
