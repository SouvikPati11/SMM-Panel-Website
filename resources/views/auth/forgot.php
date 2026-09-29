<?php $this->extend('layouts/auth'); use App\Helpers\Form; ?>
<h1>Reset your password</h1>
<p class="sub">Enter your account email and we'll send you a reset link.</p>
<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>
  <?= Form::input('email', 'Email', '', ['type' => 'email', 'required' => true, 'autocomplete' => 'email']) ?>
  <button class="btn btn-primary btn-lg btn-block" type="submit">Send reset link</button>
</form>
<p class="auth-foot"><a href="<?= e($loginUrl) ?>">Back to sign in</a></p>
