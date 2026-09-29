<?php $this->extend('layouts/auth'); use App\Helpers\Form; ?>
<h1>Create your account</h1>
<p class="sub">Free forever. Pay only for what you order.</p>
<form method="post" action="<?= e(url('/register')) ?>">
  <?= csrf_field() ?>
  <?= Form::input('username', 'Username', '', ['required' => true, 'autocomplete' => 'username', 'maxlength' => 30, 'hint' => '3–30 characters: letters, numbers, underscore.']) ?>
  <?= Form::input('email', 'Email', '', ['type' => 'email', 'required' => true, 'autocomplete' => 'email', 'maxlength' => 190]) ?>
  <?php if ($mobileMode !== 'off'): ?><?= Form::input('mobile', 'Mobile number' . ($mobileMode === 'optional' ? ' (optional)' : ''), '', ['type' => 'tel', 'required' => $mobileMode === 'required', 'autocomplete' => 'tel', 'inputmode' => 'tel', 'maxlength' => 20, 'placeholder' => '+1 555 010 0123', 'hint' => 'Include your country code.']) ?><?php endif ?>
  <div class="form-grid">
    <?= Form::input('password', 'Password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'maxlength' => 128]) ?>
    <?= Form::input('password_confirmation', 'Confirm password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'maxlength' => 128]) ?>
  </div>
  <p class="hint" style="margin-top:-8px">At least 8 characters with a letter and a number.</p>
  <?php if ($ref !== ''): ?><input type="hidden" name="ref" value="<?= e($ref) ?>"><div class="alert alert-info"><?= icon('gift') ?><div>You were invited by a friend (code <strong><?= e($ref) ?></strong>).</div></div><?php endif ?>
  <div class="field"><?= Form::check('terms', 'I agree to the <a href="' . e(url('/terms')) . '" target="_blank">Terms of Service</a> and <a href="' . e(url('/privacy')) . '" target="_blank">Privacy Policy</a>.', false) ?></div>
  <button class="btn btn-primary btn-lg btn-block" type="submit">Create account</button>
</form>
<p class="auth-foot">Already have an account? <a href="<?= e(url('/login')) ?>">Sign in</a></p>
