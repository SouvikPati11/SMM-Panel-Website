<?php $this->extend('layouts/user');
$tabs = ['' => 'All', 'pending' => 'Pending', 'processing' => 'Processing', 'in_progress' => 'In progress', 'completed' => 'Completed', 'partial' => 'Partial', 'cancelled' => 'Cancelled', 'failed' => 'Failed'];
$total = array_sum($counts); ?>
<div class="page-head">
  <div><h1>My orders</h1><p><?= number_format($orders->total) ?> order<?= $orders->total === 1 ? '' : 's' ?><?= $status ? ' with status ' . e(str_replace('_', ' ', $status)) : '' ?>.</p></div>
  <a class="btn btn-primary" href="<?= e(url('/order')) ?>"><?= icon('plus') ?> New order</a>
</div>
<div class="chips">
  <?php foreach ($tabs as $k => $label): $n = $k === '' ? $total : (int) ($counts[$k] ?? 0); if ($k !== '' && !$n && $status !== $k) { continue; } ?>
    <a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(url('/orders' . ($k ? '?status=' . $k : ''))) ?>"><?= e($label) ?> <span class="count"><?= number_format($n) ?></span></a>
  <?php endforeach ?>
</div>
<form class="toolbar" method="get" action="<?= e(url('/orders')) ?>">
  <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
  <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search by order ID or link">
  <button class="btn btn-secondary" type="submit"><?= icon('search') ?> Search</button>
</form>
<div class="card">
  <?php if (!$orders->items): ?>
    <div class="empty"><?= icon('list') ?><h3>No orders found</h3><p><?= $q || $status ? 'Try clearing the filters.' : 'Your orders will appear here.' ?></p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>ID</th><th>Service</th><th>Link</th><th class="num">Qty</th><th class="num">Start</th><th class="num">Remains</th><th class="num">Charge</th><th>Status</th><th>Date</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($orders->items as $o): ?>
      <tr>
        <td data-label="ID"><a class="mono" href="<?= e(url('/orders/' . $o['id'])) ?>">#<?= (int) $o['id'] ?></a></td>
        <td class="cell-main"><span class="cell-title"><?= e(str_limit($o['service'], 70)) ?></span></td>
        <td data-label="Link" class="link-cell"><?= link_html($o['link']) ?></td>
        <td data-label="Quantity" class="num"><?= number_format((int) $o['quantity']) ?><?= $o['runs'] ? ' ×' . (int) $o['runs'] : '' ?></td>
        <td data-label="Start count" class="num"><?= $o['start_count'] !== null ? number_format((int) $o['start_count']) : '—' ?></td>
        <td data-label="Remains" class="num"><?= $o['remains'] !== null ? number_format((int) $o['remains']) : '—' ?></td>
        <td data-label="Charge" class="num nowrap"><?= e(money($o['charge'])) ?></td>
        <td data-label="Status"><?= status_badge($o['status']) ?></td>
        <td data-label="Date" class="nowrap text-sm"><?= e(fmt_date($o['created_at'])) ?></td>
        <td class="actions">
          <?php if ($o['can_refill'] && in_array($o['status'], ['completed', 'partial'], true) && setting('refill_enabled', '1') === '1'): ?>
            <form class="inline-form" method="post" action="<?= e(url('/orders/' . $o['id'] . '/refill')) ?>"><?= csrf_field() ?><button class="btn btn-soft btn-sm" type="submit"><?= icon('refresh') ?> Refill</button></form>
          <?php endif ?>
          <a class="btn btn-ghost btn-sm" href="<?= e(url('/orders/' . $o['id'])) ?>">Details</a>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <div class="flex justify-between items-center wrap" style="padding:0 16px"><span class="text-sm text-muted"><?= e($orders->summary()) ?></span><?= $orders->links(\App\Core\App::request()) ?></div>
  <?php endif ?>
</div>
