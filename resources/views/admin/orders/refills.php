<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>Refills</h1></div></div>
<div class="chips"><?php foreach (['' => 'All', 'pending' => 'Pending', 'processing' => 'Processing', 'completed' => 'Completed', 'rejected' => 'Rejected', 'failed' => 'Failed'] as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(admin_url('refills' . ($k ? '?status=' . $k : ''))) ?>"><?= $l ?></a><?php endforeach ?></div>
<div class="card"><div class="table-wrap"><table class="table table-cards">
  <thead><tr><th>ID</th><th>Order</th><th>User</th><th>Service</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody>
  <?php foreach ($refills->items as $r): ?>
    <tr><td data-label="ID" class="mono">#<?= (int) $r['id'] ?></td><td data-label="Order"><a href="<?= e(admin_url('orders/' . $r['order_id'])) ?>">#<?= (int) $r['order_id'] ?></a></td><td data-label="User"><?= e($r['username']) ?></td>
      <td class="cell-main"><?= e(str_limit($r['service'], 50)) ?><?php if ($r['message']): ?><div class="cell-sub"><?= e($r['message']) ?></div><?php endif ?></td><td data-label="Status"><?= status_badge($r['status']) ?></td><td data-label="Date" class="text-sm"><?= e(fmt_date($r['created_at'])) ?></td>
      <td class="actions"><?php if (can('orders.manage') && in_array($r['status'], ['pending', 'processing', 'failed'], true)): ?><form class="inline-form" method="post" action="<?= e(admin_url('refills/' . $r['id'])) ?>"><?= csrf_field() ?><select class="select" name="status" style="min-height:32px;width:auto;display:inline-block"><option value="completed">Completed</option><option value="rejected">Rejected</option><option value="processing">Processing</option></select> <button class="btn btn-soft btn-sm" type="submit">Set</button></form><?php endif ?></td></tr>
  <?php endforeach ?><?php if (!$refills->items): ?><tr><td colspan="7" class="text-center text-muted" style="padding:28px">No refills.</td></tr><?php endif ?>
</tbody></table></div><?= $refills->links(\App\Core\App::request()) ?></div>
