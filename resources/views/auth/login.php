<?php $this->extend('layouts/auth'); use App\Helpers\Form; ?>
<h1>Welcome back</h1>
<p class="sub">Sign in to manage your orders and balance.</p>
<form method="post" action="<?= e(url('/login')) ?>">
  <?= csrf_field() ?>
  <?= Form::input('login', 'Username or email', '', ['required' => true, 'autocomplete' => 'username', 'maxlength' => 190]) ?>
  <div class="field">
    <div class="flex justify-between items-center"><label for="f_password" class="label">Password</label><a class="text-sm" href="<?= e(url('/forgot-password')) ?>">Forgot password?</a></div>
    <input class="input" id="f_password" name="password" type="password" required autocomplete="current-password" maxlength="128">
  </div>
  <button class="btn btn-primary btn-lg btn-block" type="submit">Sign in</button>
</form>
<?php if (setting('registration_enabled', '1') === '1'): ?><p class="auth-foot">New here? <a href="<?= e(url('/register')) ?>">Create an account</a></p><?php endif ?>
