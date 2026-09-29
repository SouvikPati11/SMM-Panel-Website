<?php $this->extend('layouts/user'); ?>
<div class="page-head"><div><h1>Support tickets</h1><p>We usually reply within a few hours.</p></div><a class="btn btn-primary" href="<?= e(url('/tickets/new')) ?>"><?= icon('plus') ?> New ticket</a></div>
<div class="card">
  <?php if (!$tickets->items): ?>
    <div class="empty"><?= icon('chat') ?><h3>No tickets yet</h3><p>Questions about an order or payment? We're here to help.</p><a class="btn btn-primary mt-1" href="<?= e(url('/tickets/new')) ?>">Open a ticket</a></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>ID</th><th>Subject</th><th>Category</th><th>Status</th><th>Last update</th></tr></thead>
    <tbody><?php foreach ($tickets->items as $t): ?>
      <tr>
        <td data-label="ID" class="mono">#<?= (int) $t['id'] ?></td>
        <td class="cell-main"><a class="cell-title" href="<?= e(url('/tickets/' . $t['id'])) ?>"><?= e($t['subject']) ?></a><?php if ((int) $t['user_unread'] === 1): ?> <span class="badge badge-primary">New reply</span><?php endif ?></td>
        <td data-label="Category"><?= e(\App\Services\TicketService::CATEGORIES[$t['category']] ?? $t['category']) ?></td>
        <td data-label="Status"><?= status_badge($t['status']) ?></td>
        <td data-label="Updated" class="text-sm nowrap"><?= e(time_ago($t['last_reply_at'])) ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= $tickets->links(\App\Core\App::request()) ?>
  <?php endif ?>
</div>
