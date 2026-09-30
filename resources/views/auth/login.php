<?php $this->extend('layouts/auth'); ?>
<div class="auth-head">
  <h1>Welcome back</h1>
  <p class="sub">Sign in to manage your orders and balance.</p>
</div>
<?php if (!empty($google)): ?><?= $this->partial('partials/google-button', ['intent' => 'login', 'label' => 'Continue with Google', 'divider' => 'or sign in with your email']) ?><?php endif ?>
<form class="auth-form" method="post" action="<?= e(url('/login')) ?>" data-auth-form>
  <?= csrf_field() ?>
  <div class="field">
    <label for="f_login">Username or email</label>
    <div class="input-icon"><span class="input-icon-glyph"><?= icon('user') ?></span><input class="input" id="f_login" name="login" value="<?= e(old('login')) ?>" required autocomplete="username" maxlength="190" autocapitalize="none" spellcheck="false"></div>
  </div>
  <div class="field">
    <div class="label-row"><label for="f_password">Password</label><a class="text-sm" href="<?= e(url('/forgot-password')) ?>">Forgot password?</a></div>
    <div class="input-icon"><span class="input-icon-glyph"><?= icon('lock') ?></span><input class="input" id="f_password" name="password" type="password" required autocomplete="current-password" maxlength="128"></div>
  </div>
  <label class="check auth-remember"><input type="checkbox" name="remember" value="1"<?= old('remember') === '1' ? ' checked' : '' ?>> <span>Keep me signed in for <?= (int) \App\Services\RememberService::DAYS ?> days</span></label>
  <button class="btn btn-primary btn-lg btn-block" type="submit" data-loading-text="Signing in…"><span>Sign in</span></button>
</form>
<?php if (setting('registration_enabled', '1') === '1'): ?><p class="auth-foot">New here? <a href="<?= e(url('/register')) ?>">Create an account</a></p><?php endif ?>
