<?php
/** reCAPTCHA widget for an auth form; $captcha comes from RecaptchaService::widget() (null = OFF, render nothing). */
if (empty($captcha)) {
    return;
}
$v3 = $captcha['version'] === 'v3'; ?>
<?php if ($v3): ?>
<input type="hidden" name="g-recaptcha-response" value="" data-recaptcha-v3="<?= e($captcha['site_key']) ?>" data-recaptcha-action="<?= e($captcha['action']) ?>">
<p class="recaptcha-note">Protected by reCAPTCHA — Google <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Privacy</a> &amp; <a href="https://policies.google.com/terms" target="_blank" rel="noopener">Terms</a> apply.</p>
<script src="https://www.google.com/recaptcha/api.js?render=<?= e(rawurlencode($captcha['site_key'])) ?>" async defer></script>
<?php else: ?>
<div class="field recaptcha-field">
  <div class="g-recaptcha" data-sitekey="<?= e($captcha['site_key']) ?>"></div>
  <div class="field-error" data-recaptcha-error hidden role="alert">Please tick “I'm not a robot”.</div>
</div>
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php endif ?>
