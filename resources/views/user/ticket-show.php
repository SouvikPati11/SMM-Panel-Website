<?php $this->extend('layouts/user'); ?>
<div class="page-head">
  <div><ol class="breadcrumb"><li><a href="<?= e(url('/tickets')) ?>">Tickets</a></li><li>#<?= (int) $ticket['id'] ?></li></ol>
  <h1><?= e($ticket['subject']) ?></h1>
  <p><?= status_badge($ticket['status']) ?> · <?= e(\App\Services\TicketService::CATEGORIES[$ticket['category']] ?? '') ?><?= $ticket['order_ref'] ? ' · Orders: ' . e($ticket['order_ref']) : '' ?></p></div>
  <?php if ($ticket['status'] !== 'closed'): ?><form method="post" action="<?= e(url('/tickets/' . $ticket['id'] . '/close')) ?>" data-confirm="Close this ticket?"><?= csrf_field() ?><button class="btn btn-secondary" type="submit">Close ticket</button></form><?php endif ?>
</div>
<div class="card mb-2"><div class="card-body">
  <?php foreach ($messages as $m): $staff = $m['admin_id'] !== null; ?>
  <div class="ticket-msg <?= $staff ? 'staff' : '' ?>">
    <span class="avatar"><?= $staff ? 'S' : e(mb_substr((string) $m['username'], 0, 1)) ?></span>
    <div class="bubble">
      <div class="meta"><strong style="color:var(--text)"><?= $staff ? 'Support team' : 'You' ?></strong><span><?= e(fmt_date($m['created_at'])) ?></span></div>
      <?php if ((int) $m['is_deleted'] === 1): ?><div class="body text-muted"><em>Message removed.</em></div><?php else: ?>
      <div class="body"><?= e($m['message']) ?></div>
      <?php foreach ($m['attachments'] as $a): ?><a class="chip mt-1" href="<?= e(url('/tickets/attachment/' . $a['id'])) ?>" target="_blank" rel="noopener"><?= icon('file') ?> <?= e($a['original']) ?></a><?php endforeach ?>
      <?php endif ?>
    </div>
  </div>
  <?php endforeach ?>
</div></div>
<?php if ($ticket['status'] !== 'closed'): ?>
<div class="card"><div class="card-body">
  <form method="post" action="<?= e(url('/tickets/' . $ticket['id'] . '/reply')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <?= \App\Helpers\Form::textarea('message', 'Your reply', '', ['required' => true, 'rows' => 4, 'maxlength' => 5000]) ?>
    <div class="flex justify-between items-center wrap gap-1">
      <?php if (setting('ticket_attachments', '1') === '1'): ?><input class="input" type="file" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf,text/plain" style="max-width:340px"><?php endif ?>
      <button class="btn btn-primary" type="submit"><?= icon('send') ?> Send reply</button>
    </div>
  </form>
</div></div>
<?php else: ?>
<div class="alert alert-info"><?= icon('info') ?><div>This ticket is closed. <a href="<?= e(url('/tickets/new')) ?>">Open a new ticket</a> if you need more help.</div></div>
<?php endif ?>
