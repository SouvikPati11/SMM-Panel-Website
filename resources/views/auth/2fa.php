<?php $this->extend('layouts/auth'); ?>
<h1>Two-factor authentication</h1>
<p class="sub">Enter the 6-digit code from your authenticator app.</p>
<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>
  <div class="field">
    <label for="code" class="label">Authentication code</label>
    <input class="input" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus style="font-size:22px;letter-spacing:.3em;text-align:center">
  </div>
  <button class="btn btn-primary btn-lg btn-block" type="submit">Verify</button>
</form>
<p class="auth-foot">Lost your device? Contact support to reset 2FA.</p>
