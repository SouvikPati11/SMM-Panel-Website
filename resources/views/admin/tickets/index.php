<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>Support tickets</h1></div></div>
<div class="chips"><?php foreach (['active' => 'Open & pending', 'open' => 'Open', 'pending' => 'Awaiting reply', 'answered' => 'Answered', 'closed' => 'Closed', 'all' => 'All'] as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(admin_url('tickets?status=' . $k)) ?>"><?= $l ?></a><?php endforeach ?><a class="chip" href="<?= e(admin_url('tickets?mine=1&status=all')) ?>">Assigned to me</a></div>
<form class="toolbar" method="get" action="<?= e(admin_url('tickets')) ?>"><input type="hidden" name="status" value="<?= e($status) ?>"><input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Subject, username, ticket or order ID"><button class="btn btn-secondary" type="submit"><?= icon('search') ?></button></form>
<div class="card"><div class="table-wrap"><table class="table table-cards">
  <thead><tr><th>ID</th><th>Subject</th><th>User</th><th>Category</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Last reply</th></tr></thead><tbody>
  <?php foreach ($tickets->items as $t): ?>
    <tr><td data-label="ID" class="mono">#<?= (int) $t['id'] ?></td><td class="cell-main"><a class="cell-title" href="<?= e(admin_url('tickets/' . $t['id'])) ?>"><?= e($t['subject']) ?></a><?= (int) $t['admin_unread'] ? ' <span class="badge badge-primary">New</span>' : '' ?></td>
      <td data-label="User"><?= e($t['username']) ?></td><td data-label="Category"><?= e(\App\Services\TicketService::CATEGORIES[$t['category']] ?? $t['category']) ?></td><td data-label="Priority"><?= status_badge($t['priority']) ?></td><td data-label="Status"><?= status_badge($t['status']) ?></td>
      <td data-label="Assigned"><?= e($t['assignee'] ?: '—') ?></td><td data-label="Last reply" class="text-sm nowrap"><?= e(time_ago($t['last_reply_at'])) ?></td></tr>
  <?php endforeach ?><?php if (!$tickets->items): ?><tr><td colspan="8" class="text-center text-muted" style="padding:28px">No tickets.</td></tr><?php endif ?>
</tbody></table></div><?= $tickets->links(\App\Core\App::request()) ?></div>
