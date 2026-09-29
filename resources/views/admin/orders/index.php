<?php $this->extend('layouts/admin'); $req = \App\Core\App::request(); ?>
<div class="page-head"><div><h1>Orders</h1><p><?= number_format($orders->total) ?> matching</p></div></div>
<div class="chips">
  <a class="chip <?= !$f['status'] && !$f['attention'] ? 'active' : '' ?>" href="<?= e(admin_url('orders')) ?>">All <span class="count"><?= number_format(array_sum($counts)) ?></span></a>
  <?php if ($attention): ?><a class="chip <?= $f['attention'] ? 'active' : '' ?>" href="<?= e(admin_url('orders?attention=1')) ?>" style="border-color:var(--warning)"><?= icon('alert') ?> Needs review <span class="count"><?= $attention ?></span></a><?php endif ?>
  <?php foreach (['pending', 'processing', 'in_progress', 'completed', 'partial', 'cancelled', 'refunded', 'failed'] as $s): if (empty($counts[$s])) { continue; } ?>
    <a class="chip <?= $f['status'] === $s ? 'active' : '' ?>" href="<?= e(admin_url('orders?status=' . $s)) ?>"><?= e(ucwords(str_replace('_', ' ', $s))) ?> <span class="count"><?= number_format((int) $counts[$s]) ?></span></a>
  <?php endforeach ?>
</div>
<form class="toolbar" method="get" action="<?= e(admin_url('orders')) ?>">
  <?php if ($f['status']): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif ?>
  <input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Order ID, provider ID, link or username">
  <select class="select" name="provider"><option value="">All providers</option><?php foreach ($providers as $id => $n): ?><option value="<?= (int) $id ?>"<?= $f['provider'] === (int) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach ?></select>
  <input class="input" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From date" style="max-width:170px">
  <input class="input" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To date" style="max-width:170px">
  <button class="btn btn-secondary" type="submit"><?= icon('filter') ?> Filter</button>
</form>
<div class="card">
  <?php if (!$orders->items): ?><div class="empty"><?= icon('list') ?><h3>No orders found</h3></div><?php else: ?>
  <div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>ID</th><th>User</th><th>Service</th><th>Link</th><th class="num">Qty</th><th class="num">Charge</th><th>Provider</th><th>Status</th><th>Date</th></tr></thead>
    <tbody><?php foreach ($orders->items as $o): ?>
      <tr class="<?= (int) $o['needs_attention'] ? 'row-attention' : '' ?>">
        <td data-label="ID"><a class="mono" href="<?= e(admin_url('orders/' . $o['id'])) ?>">#<?= (int) $o['id'] ?></a></td>
        <td data-label="User"><a href="<?= e(admin_url('users/' . $o['user_id'])) ?>"><?= e($o['username']) ?></a></td>
        <td class="cell-main"><span class="cell-title"><?= e(str_limit($o['service'], 50)) ?></span><div class="cell-sub">svc <?= (int) $o['service_id'] ?> · <?= e($o['source']) ?></div></td>
        <td data-label="Link" class="link-cell"><?= link_html($o['link']) ?></td>
        <td data-label="Qty" class="num"><?= number_format((int) $o['quantity']) ?></td>
        <td data-label="Charge" class="num nowrap"><?= e(money($o['charge'])) ?></td>
        <td data-label="Provider" class="text-sm"><?= e($o['provider'] ?: 'Manual') ?><?php if ($o['provider_order_id']): ?><div class="cell-sub mono"><?= e($o['provider_order_id']) ?></div><?php endif ?></td>
        <td data-label="Status"><?= status_badge($o['status']) ?><?php if (in_array($o['submit_state'], ['queued', 'unknown', 'submitting'], true) && $o['status'] === 'pending' && $o['provider_id']): ?><div class="cell-sub"><?= e($o['submit_state']) ?></div><?php endif ?></td>
        <td data-label="Date" class="text-sm nowrap"><?= e(fmt_date($o['created_at'])) ?></td></tr>
    <?php endforeach ?></tbody></table></div>
  <div class="flex justify-between items-center wrap" style="padding:0 16px"><span class="text-sm text-muted"><?= e($orders->summary()) ?></span><?= $orders->links($req) ?></div>
  <?php endif ?>
</div>
