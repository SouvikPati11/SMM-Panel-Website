<?php $this->extend('layouts/admin'); use App\Helpers\Form; $s = fn (string $k) => (string) setting($k); ?>
<div class="page-head"><div><h1>Email settings</h1><p>Queue: <?= (int) ($queue['queued'] ?? 0) ?> queued · <?= (int) ($queue['sent'] ?? 0) ?> sent · <?= (int) ($queue['failed'] ?? 0) ?> failed. Emails are sent by the <code>notifications</code> cron task.</p></div></div>
<div class="grid-main">
  <div class="card"><div class="card-body">
    <form method="post" action="<?= e(admin_url('settings/email')) ?>"><?= csrf_field() ?>
      <?= Form::select('mail_driver', 'Driver', ['smtp' => 'SMTP (recommended)', 'mail' => 'PHP mail()', 'log' => 'Log only (development)'], $s('mail_driver') ?: 'smtp') ?>
      <div class="form-grid"><?= Form::input('mail_host', 'SMTP host', $s('mail_host'), ['placeholder' => 'smtp.hostinger.com / mail.yourdomain.com']) ?><?= Form::input('mail_port', 'Port', $s('mail_port') ?: '587', ['type' => 'number']) ?>
      <?= Form::select('mail_encryption', 'Encryption', ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None'], $s('mail_encryption') ?: 'tls') ?><?= Form::input('mail_username', 'Username', $s('mail_username'), ['autocomplete' => 'off']) ?></div>
      <?= Form::input('mail_password', 'Password', '', ['type' => 'password', 'autocomplete' => 'new-password', 'hint' => $hasPassword ? 'Stored encrypted — leave empty to keep.' : 'Stored encrypted.']) ?>
      <div class="form-grid"><?= Form::input('mail_from', 'From address', $s('mail_from'), ['type' => 'email', 'required' => true]) ?><?= Form::input('mail_from_name', 'From name', $s('mail_from_name')) ?></div>
      <?= Form::input('admin_notify_email', 'Admin alerts to (tickets, manual payments, orders needing review)', $s('admin_notify_email'), ['type' => 'email']) ?>
      <?= Form::toggle('email_notify_orders', 'Email users on order status changes', setting('email_notify_orders') === '1') ?>
      <?= Form::toggle('email_notify_payments', 'Email users when funds are added', setting('email_notify_payments') === '1') ?>
      <?= Form::toggle('email_notify_tickets', 'Email users on ticket replies', setting('email_notify_tickets') === '1') ?>
      <button class="btn btn-primary" type="submit">Save</button></form>
  </div></div>
  <div>
    <div class="card mb-2"><div class="card-header"><h2>Send test</h2></div><div class="card-body">
      <form method="post" action="<?= e(admin_url('settings/email/test')) ?>"><?= csrf_field() ?><?= Form::input('to', 'To', auth_admin()['email'], ['type' => 'email']) ?><button class="btn btn-secondary btn-block" type="submit">Send test email</button></form></div></div>
    <?php if ($failed): ?><div class="card"><div class="card-header"><h2>Recent failures</h2></div><ul class="list-plain list-rows"><?php foreach ($failed as $f): ?><li><div style="min-width:0"><div class="cell-title truncate"><?= e($f['subject']) ?></div><div class="cell-sub"><?= e($f['to_email']) ?></div><div class="text-xs text-danger"><?= e($f['last_error']) ?></div></div></li><?php endforeach ?></ul></div><?php endif ?>
  </div>
</div>
