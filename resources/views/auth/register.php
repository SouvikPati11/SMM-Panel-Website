<?php $this->extend('layouts/auth'); use App\Helpers\Form; ?>
<div class="auth-head">
  <h1>Create your account</h1>
  <p class="sub">Free to join. Pay only for what you order.</p>
</div>
<?php if (!empty($google)): ?><?= $this->partial('partials/google-button', ['intent' => 'register', 'label' => 'Sign up with Google', 'divider' => 'or register with your email']) ?><?php endif ?>
<form class="auth-form" method="post" action="<?= e(url('/register')) ?>" data-auth-form>
  <?= csrf_field() ?>
  <div class="field">
    <label for="f_username">Username</label>
    <div class="input-icon"><span class="input-icon-glyph"><?= icon('user') ?></span><input class="input" id="f_username" name="username" value="<?= e(old('username')) ?>" required autocomplete="username" minlength="3" maxlength="30" pattern="[A-Za-z0-9_]{3,30}" autocapitalize="none" spellcheck="false" aria-describedby="h_username"></div>
    <div class="hint" id="h_username">3–30 characters: letters, numbers and underscore.</div>
  </div>
  <div class="field">
    <label for="f_email">Email</label>
    <div class="input-icon"><span class="input-icon-glyph"><?= icon('mail') ?></span><input class="input" id="f_email" name="email" type="email" value="<?= e(old('email')) ?>" required autocomplete="email" maxlength="190" autocapitalize="none" spellcheck="false"></div>
    <?php if (setting('email_verification', '0') === '1'): ?><div class="hint">We will send you a link to confirm this address.</div><?php endif ?>
  </div>
  <?php if ($mobileMode !== 'off'): ?>
  <div class="field">
    <label for="f_mobile">Mobile number<?= $mobileMode === 'optional' ? ' <span class="text-muted fw-normal">(optional)</span>' : '' ?></label>
    <div class="input-icon"><span class="input-icon-glyph"><?= icon('phone') ?></span><input class="input" id="f_mobile" name="mobile" type="tel" value="<?= e(old('mobile')) ?>" <?= $mobileMode === 'required' ? 'required ' : '' ?>autocomplete="tel" inputmode="tel" maxlength="20" placeholder="+1 555 010 0123" pattern="[+0-9 ().\-]{7,20}" aria-describedby="h_mobile"></div>
    <div class="hint" id="h_mobile">Include your country code, e.g. +44 7700 900123.</div>
  </div>
  <?php endif ?>
  <div class="form-grid">
    <div class="field">
      <label for="f_password">Password</label>
      <div class="input-icon"><span class="input-icon-glyph"><?= icon('lock') ?></span><input class="input" id="f_password" name="password" type="password" required autocomplete="new-password" minlength="8" maxlength="128" data-strength="#pw-strength"></div>
    </div>
    <div class="field">
      <label for="f_password_confirmation">Confirm password</label>
      <div class="input-icon"><span class="input-icon-glyph"><?= icon('lock') ?></span><input class="input" id="f_password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" minlength="8" maxlength="128" data-match="#f_password"></div>
    </div>
  </div>
  <ul class="pw-rules" id="pw-strength" aria-live="polite">
    <li data-rule="len">8+ characters</li><li data-rule="letter">A letter</li><li data-rule="digit">A number</li><li data-rule="match">Passwords match</li>
  </ul>
  <?php if ($ref !== ''): ?><input type="hidden" name="ref" value="<?= e($ref) ?>"><div class="alert alert-info"><?= icon('gift') ?><div>You were invited by a friend (code <strong><?= e($ref) ?></strong>).</div></div><?php endif ?>
  <div class="field"><?= Form::check('terms', 'I agree to the <a href="' . e(url('/terms')) . '" target="_blank" rel="noopener">Terms of Service</a> and <a href="' . e(url('/privacy')) . '" target="_blank" rel="noopener">Privacy Policy</a>.', old('terms') === '1') ?></div>
  <?= $this->partial('partials/recaptcha', ['captcha' => $captcha ?? null]) ?>
  <button class="btn btn-primary btn-lg btn-block" type="submit" data-loading-text="Creating your account…"><span>Create account</span></button>
</form>
<p class="auth-foot">Already have an account? <a href="<?= e(url('/login')) ?>">Sign in</a></p>
