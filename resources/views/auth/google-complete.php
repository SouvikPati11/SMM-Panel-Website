<?php $this->extend('layouts/auth'); use App\Helpers\Form; ?>
<div class="auth-head">
  <h1>One last step</h1>
  <p class="sub">You're signing up with Google as <strong><?= e($profile['email']) ?></strong>. Choose a username to finish.</p>
</div>
<form class="auth-form" method="post" action="<?= e(url('/auth/google/complete')) ?>" data-auth-form>
  <?= csrf_field() ?>
  <div class="field">
    <label for="f_username">Username</label>
    <div class="input-icon"><span class="input-icon-glyph"><?= icon('user') ?></span><input class="input" id="f_username" name="username" value="<?= e(old('username', $suggest)) ?>" required autocomplete="username" minlength="3" maxlength="30" pattern="[A-Za-z0-9_]{3,30}" autocapitalize="none" spellcheck="false"></div>
    <div class="hint">3–30 characters: letters, numbers and underscore.</div>
  </div>
  <?php if ($mobileMode !== 'off'): ?>
  <div class="field">
    <label for="f_mobile">Mobile number<?= $mobileMode === 'optional' ? ' <span class="text-muted fw-normal">(optional)</span>' : '' ?></label>
    <div class="input-icon"><span class="input-icon-glyph"><?= icon('phone') ?></span><input class="input" id="f_mobile" name="mobile" type="tel" value="<?= e(old('mobile')) ?>" <?= $mobileMode === 'required' ? 'required ' : '' ?>autocomplete="tel" inputmode="tel" maxlength="20" placeholder="+1 555 010 0123"></div>
    <div class="hint">Include your country code, e.g. +44 7700 900123.</div>
  </div>
  <?php endif ?>
  <div class="field"><?= Form::check('terms', 'I agree to the <a href="' . e(url('/terms')) . '" target="_blank" rel="noopener">Terms of Service</a> and <a href="' . e(url('/privacy')) . '" target="_blank" rel="noopener">Privacy Policy</a>.', old('terms') === '1') ?></div>
  <button class="btn btn-primary btn-lg btn-block" type="submit" data-loading-text="Creating your account…"><span>Create account</span></button>
</form>
<p class="auth-foot">Not you? <a href="<?= e(url('/register')) ?>">Register with email instead</a></p>
