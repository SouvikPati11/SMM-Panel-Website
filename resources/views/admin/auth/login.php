<?php $this->extend('layouts/auth'); \App\Core\View::share('sideTitle', 'Administration'); use App\Helpers\Form; ?>
<h1>Admin sign in</h1>
<p class="sub">Restricted area. All access is logged.</p>
<form method="post" action="<?= e(admin_url('login')) ?>">
  <?= csrf_field() ?>
  <?= Form::input('login', 'Username or email', '', ['required' => true, 'autocomplete' => 'username']) ?>
  <?= Form::input('password', 'Password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
  <button class="btn btn-primary btn-lg btn-block" type="submit"><?= icon('lock') ?> Sign in</button>
</form>
<p class="auth-foot"><a href="<?= e(admin_url('forgot-password')) ?>">Forgot password?</a></p>
