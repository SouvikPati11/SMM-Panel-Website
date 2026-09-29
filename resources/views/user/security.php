<?php $this->extend('layouts/user'); use App\Helpers\Form; ?>
<div class="page-head"><div><h1>Security</h1><p>Protect your account and your balance.</p></div></div>
<div class="grid-2">
  <div class="card"><div class="card-header"><h2>Change password</h2></div><div class="card-body">
    <form method="post" action="<?= e(url('/account/password')) ?>">
      <?= csrf_field() ?>
      <?= Form::input('current_password', 'Current password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
      <?= Form::input('password', 'New password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'hint' => 'At least 8 characters with a letter and a number.']) ?>
      <?= Form::input('password_confirmation', 'Confirm new password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
      <button class="btn btn-primary" type="submit">Update password</button>
    </form>
  </div></div>

  <div class="card"><div class="card-header"><h2>Two-factor authentication</h2><?= (int) $user['twofa_enabled'] === 1 ? '<span class="badge badge-success">Enabled</span>' : '<span class="badge badge-muted">Off</span>' ?></div><div class="card-body">
    <?php if ((int) $user['twofa_enabled'] === 1): ?>
      <p class="text-muted">Your account requires a code from your authenticator app at sign-in.</p>
      <form method="post" action="<?= e(url('/account/2fa/disable')) ?>" data-confirm="Disable two-factor authentication?">
        <?= csrf_field() ?>
        <?= Form::input('password', 'Confirm with your password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
        <button class="btn btn-danger" type="submit">Disable 2FA</button>
      </form>
    <?php else: ?>
      <ol class="text-sm" style="padding-left:18px;color:var(--text-2)">
        <li>Install an authenticator app (Google Authenticator, Authy, 1Password, Microsoft Authenticator).</li>
        <li>Add an account using this setup key<span class="hide-mobile">, or open the link on your phone</span>:</li>
      </ol>
      <div class="copy-box mb-1"><span><?= e(trim(chunk_split($secret, 4, ' '))) ?></span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($secret) ?>"><?= icon('copy') ?></button></div>
      <p class="text-sm"><a href="<?= e($otpUri) ?>">Open in authenticator app</a> (on this device)</p>
      <form method="post" action="<?= e(url('/account/2fa/enable')) ?>">
        <?= csrf_field() ?>
        <?= Form::input('code', 'Enter the 6-digit code to confirm', '', ['required' => true, 'inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'maxlength' => 7]) ?>
        <button class="btn btn-primary" type="submit"><?= icon('shield') ?> Enable 2FA</button>
      </form>
    <?php endif ?>
  </div></div>

  <div class="card"><div class="card-header"><h2>Sessions</h2></div><div class="card-body">
    <p class="text-muted">Signed in on a shared or lost device? Sign out everywhere except here.</p>
    <form method="post" action="<?= e(url('/account/sessions/revoke')) ?>">
      <?= csrf_field() ?>
      <?= Form::input('password', 'Confirm with your password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
      <button class="btn btn-secondary" type="submit">Sign out other sessions</button>
    </form>
  </div></div>

  <div class="card"><div class="card-header"><h2>Recent sign-in activity</h2></div>
    <?php if (!$logins): ?><div class="empty" style="padding:24px">No activity recorded.</div><?php else: ?>
    <ul class="list-plain list-rows">
      <?php foreach ($logins as $l): ?>
      <li><div style="min-width:0"><div class="cell-title"><?= e($l['ip']) ?></div><div class="cell-sub truncate"><?= e(str_limit($l['user_agent'], 60)) ?> · <?= e(fmt_date($l['created_at'])) ?></div></div><?= (int) $l['success'] === 1 ? '<span class="badge badge-success">Success</span>' : '<span class="badge badge-danger">Failed</span>' ?></li>
      <?php endforeach ?>
    </ul>
    <?php endif ?>
  </div>
</div>
