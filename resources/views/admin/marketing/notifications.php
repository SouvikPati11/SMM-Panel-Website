<?php $this->extend('layouts/admin'); use App\Helpers\Form; ?>
<div class="page-head"><div><h1>Notifications & announcements</h1></div><button class="btn btn-primary" type="button" data-open-dialog="ann-dialog" data-reset><?= icon('plus') ?> New announcement</button></div>
<div class="grid-main">
  <div class="card"><div class="card-header"><h2>Dashboard announcements</h2></div>
    <ul class="list-plain list-rows"><?php foreach ($announcements as $a): ?>
      <li><div style="min-width:0"><div class="cell-title"><?= e($a['title']) ?> <span class="badge badge-<?= $a['level'] === 'danger' ? 'danger' : ($a['level'] === 'warning' ? 'warning' : ($a['level'] === 'success' ? 'success' : 'info')) ?> no-dot"><?= e($a['level']) ?></span></div><div class="cell-sub truncate"><?= e(strip_tags($a['body'])) ?></div><div class="cell-sub"><?= $a['starts_at'] ? 'from ' . e(fmt_date($a['starts_at'])) : '' ?> <?= $a['ends_at'] ? 'until ' . e(fmt_date($a['ends_at'])) : '' ?></div></div>
        <div class="flex gap-1 items-center"><?= status_badge($a['status']) ?><button class="btn btn-ghost btn-sm" type="button" data-open-dialog="ann-dialog" data-fill="<?= json_attr(['id' => $a['id'], 'title' => $a['title'], 'body' => $a['body'], 'level' => $a['level'], 'status' => $a['status'], 'starts_at' => $a['starts_at'] ? fmt_date($a['starts_at'], 'Y-m-d\TH:i') : '', 'ends_at' => $a['ends_at'] ? fmt_date($a['ends_at'], 'Y-m-d\TH:i') : '']) ?>"><?= icon('edit') ?></button>
        <form method="post" action="<?= e(admin_url('notifications/announcement/' . $a['id'] . '/delete')) ?>" data-confirm="Delete?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash') ?></button></form></div></li>
    <?php endforeach ?><?php if (!$announcements): ?><li class="text-muted">No announcements.</li><?php endif ?></ul></div>
  <div class="card"><div class="card-header"><h2>Send notification</h2></div><div class="card-body">
    <form method="post" action="<?= e(admin_url('notifications/send')) ?>" data-confirm="Send this notification?"><?= csrf_field() ?>
      <?= Form::select('target', 'Recipients', ['user' => 'A single user', 'all' => 'All active users'], 'user') ?>
      <?= Form::input('user', 'Username or email (single user)', '') ?>
      <?= Form::input('title', 'Title', '', ['required' => true, 'maxlength' => 200]) ?>
      <?= Form::textarea('body', 'Message', '', ['rows' => 3, 'maxlength' => 1000]) ?>
      <div class="field"><?= Form::check('email', 'Also send by email (queued)', false) ?></div>
      <button class="btn btn-primary btn-block" type="submit"><?= icon('send') ?> Send</button></form>
  </div></div>
</div>
<dialog class="modal" id="ann-dialog"><div class="modal-head"><h3>Announcement</h3><button class="btn btn-ghost btn-icon btn-sm" type="button" data-close-dialog><?= icon('x') ?></button></div><div class="modal-body">
  <form method="post" action="<?= e(admin_url('notifications/announcement')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="">
    <?= Form::input('title', 'Title', '', ['required' => true]) ?>
    <?= Form::textarea('body', 'Body (basic HTML allowed)', '', ['rows' => 4, 'required' => true]) ?>
    <div class="form-grid"><?= Form::select('level', 'Style', ['info' => 'Info', 'success' => 'Success', 'warning' => 'Warning', 'danger' => 'Important'], 'info') ?><?= Form::select('status', 'Status', ['active' => 'Active', 'hidden' => 'Hidden'], 'active') ?>
    <?= Form::input('starts_at', 'Show from', '', ['type' => 'datetime-local']) ?><?= Form::input('ends_at', 'Show until', '', ['type' => 'datetime-local']) ?></div>
    <button class="btn btn-primary btn-block" type="submit">Save</button></form>
</div></dialog>
