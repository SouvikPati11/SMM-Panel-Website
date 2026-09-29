<?php $this->extend('layouts/admin'); use App\Helpers\Form; ?>
<div class="page-head"><div><ol class="breadcrumb"><li><a href="<?= e(admin_url('tickets')) ?>">Tickets</a></li><li>#<?= (int) $ticket['id'] ?></li></ol><h1><?= e($ticket['subject']) ?></h1>
  <p><a href="<?= e(admin_url('users/' . $ticket['user_id'])) ?>"><?= e($ticket['username']) ?></a> · <?= e($ticket['email']) ?> · <?= status_badge($ticket['status']) ?> <?= status_badge($ticket['priority']) ?></p></div></div>
<div class="grid-main">
  <div>
    <div class="card mb-2"><div class="card-body">
      <?php foreach ($messages as $m): $staff = $m['admin_id'] !== null; ?>
      <div class="ticket-msg <?= $staff ? 'staff' : '' ?>"><span class="avatar"><?= e(mb_substr($staff ? (string) $m['admin_username'] : (string) $m['username'], 0, 1)) ?></span>
        <div class="bubble"><div class="meta"><strong style="color:var(--text)"><?= e($staff ? ($m['admin_name'] ?: $m['admin_username']) . ' (staff)' : $m['username']) ?></strong><span><?= e(fmt_date($m['created_at'])) ?></span>
          <?php if ((int) $m['is_deleted'] === 0 && !$staff): ?><form class="inline-form" method="post" action="<?= e(admin_url('tickets/message/' . $m['id'] . '/delete')) ?>" data-confirm="Remove this message from view?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit" style="min-height:22px;padding:0 6px">remove</button></form><?php endif ?></div>
          <?php if ((int) $m['is_deleted'] === 1): ?><div class="body text-muted"><em>[removed by staff]</em> <?= e(str_limit($m['message'], 80)) ?></div><?php else: ?><div class="body"><?= e($m['message']) ?></div><?php endif ?>
          <?php foreach ($m['attachments'] as $a): ?><a class="chip mt-1" href="<?= e(admin_url('tickets/attachment/' . $a['id'])) ?>" target="_blank" rel="noopener"><?= icon('file') ?> <?= e($a['original']) ?> (<?= round($a['size'] / 1024) ?> KB)</a><?php endforeach ?></div></div>
      <?php endforeach ?>
    </div></div>
    <div class="card"><div class="card-body">
      <form method="post" action="<?= e(admin_url('tickets/' . $ticket['id'] . '/reply')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
        <?= Form::textarea('message', 'Reply', '', ['rows' => 5, 'required' => true]) ?>
        <div class="flex gap-1 wrap items-center justify-between"><input class="input" type="file" name="attachment" style="max-width:320px">
          <div class="flex gap-1"><select class="select" name="status" style="width:auto"><option value="answered">Set: Answered</option><option value="pending">Set: Pending</option><option value="closed">Set: Closed</option></select><button class="btn btn-primary" type="submit"><?= icon('send') ?> Send</button></div></div></form>
    </div></div>
  </div>
  <div>
    <div class="card mb-2"><div class="card-header"><h2>Ticket</h2></div><div class="card-body">
      <form method="post" action="<?= e(admin_url('tickets/' . $ticket['id'] . '/update')) ?>"><?= csrf_field() ?>
        <?= Form::select('status', 'Status', array_combine(\App\Services\TicketService::STATUSES, array_map('ucfirst', \App\Services\TicketService::STATUSES)), $ticket['status']) ?>
        <?= Form::select('priority', 'Priority', array_combine(\App\Services\TicketService::PRIORITIES, array_map('ucfirst', \App\Services\TicketService::PRIORITIES)), $ticket['priority']) ?>
        <?= Form::select('assigned_to', 'Assigned to', $admins, (string) $ticket['assigned_to'], ['empty' => 'Unassigned']) ?>
        <button class="btn btn-secondary btn-block" type="submit">Update</button></form>
    </div></div>
    <?php if ($orders): ?><div class="card"><div class="card-header"><h2>Referenced orders</h2></div><ul class="list-plain list-rows"><?php foreach ($orders as $o): ?><li><div><a href="<?= e(admin_url('orders/' . $o['id'])) ?>">#<?= (int) $o['id'] ?></a> <span class="text-sm text-muted"><?= e(str_limit($o['service'], 30)) ?></span></div><?= status_badge($o['status']) ?></li><?php endforeach ?></ul></div><?php endif ?>
  </div>
</div>
