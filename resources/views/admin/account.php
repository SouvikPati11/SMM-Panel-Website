<?php $this->extend('layouts/admin'); use App\Helpers\Form; ?>
<div class="page-head"><div><h1>My account</h1><p><?= e($admin['email']) ?> · <?= (int) $admin['is_super'] === 1 ? 'Super admin' : e(implode(', ', array_column($roles, 'name')) ?: 'No roles') ?></p></div></div>
<div class="grid-2">
  <div class="card"><div class="card-header"><h2>Change password</h2></div><div class="card-body">
    <form method="post" action="<?= e(admin_url('account/password')) ?>"><?= csrf_field() ?>
      <?= Form::input('current_password', 'Current password', '', ['type' => 'password', 'required' => true]) ?>
      <?= Form::input('password', 'New password', '', ['type' => 'password', 'required' => true]) ?>
      <?= Form::input('password_confirmation', 'Confirm', '', ['type' => 'password', 'required' => true]) ?>
      <button class="btn btn-primary" type="submit">Update password</button></form>
  </div></div>
  <div class="card"><div class="card-header"><h2>Two-factor authentication</h2><?= (int) $admin['twofa_enabled'] === 1 ? '<span class="badge badge-success">Enabled</span>' : '<span class="badge badge-warning">Recommended</span>' ?></div><div class="card-body">
    <?php if ((int) $admin['twofa_enabled'] === 1): ?>
      <form method="post" action="<?= e(admin_url('account/2fa/disable')) ?>" data-confirm="Disable 2FA for your admin account?"><?= csrf_field() ?>
        <?= Form::input('password', 'Password', '', ['type' => 'password', 'required' => true]) ?><button class="btn btn-danger" type="submit">Disable 2FA</button></form>
    <?php else: ?>
      <p class="text-sm">Add this key to your authenticator app, then confirm with a code.</p>
      <div class="copy-box mb-1"><span><?= e(trim(chunk_split($secret, 4, ' '))) ?></span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($secret) ?>"><?= icon('copy') ?></button></div>
      <p class="text-sm"><a href="<?= e($otpUri) ?>">Open in authenticator app</a></p>
      <form method="post" action="<?= e(admin_url('account/2fa/enable')) ?>"><?= csrf_field() ?>
        <?= Form::input('code', '6-digit code', '', ['required' => true, 'inputmode' => 'numeric', 'autocomplete' => 'one-time-code']) ?><button class="btn btn-primary" type="submit">Enable 2FA</button></form>
    <?php endif ?>
  </div></div>
</div>
