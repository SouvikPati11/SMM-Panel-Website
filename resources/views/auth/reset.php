<?php $this->extend('layouts/auth'); use App\Helpers\Form; ?>
<h1>Choose a new password</h1>
<p class="sub">This will sign you out on all other devices.</p>
<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>
  <?= Form::input('password', 'New password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'hint' => 'At least 8 characters with a letter and a number.']) ?>
  <?= Form::input('password_confirmation', 'Confirm new password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
  <button class="btn btn-primary btn-lg btn-block" type="submit">Update password</button>
</form>
