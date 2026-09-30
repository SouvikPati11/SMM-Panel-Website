<?php $this->extend('layouts/admin'); use App\Helpers\Form;
$tabs = ['general' => 'General', 'contact' => 'Contact & social', 'currency' => 'Currency', 'users' => 'Users', 'orders' => 'Orders', 'funds' => 'Funds', 'referral' => 'Referral', 'api' => 'API', 'tickets' => 'Tickets'];
$s = fn (string $k) => (string) setting($k); $on = fn (string $k) => setting($k) === '1'; ?>
<div class="page-head"><div><h1>Settings</h1></div><div class="btn-group"><a class="btn btn-secondary" href="<?= e(admin_url('settings/email')) ?>"><?= icon('mail') ?> Email</a><a class="btn btn-secondary" href="<?= e(admin_url('seo')) ?>"><?= icon('globe') ?> SEO</a></div></div>
<div class="tabs"><?php foreach ($tabs as $k => $l): ?><a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= e(admin_url('settings?tab=' . $k)) ?>"><?= e($l) ?></a><?php endforeach ?></div>
<form method="post" action="<?= e(admin_url('settings')) ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="tab" value="<?= e($tab) ?>">
<div class="card" style="max-width:860px"><div class="card-body">
<?php if ($tab === 'general'): ?>
  <div class="form-grid"><?= Form::input('site_name', 'Website name', $s('site_name'), ['required' => true]) ?>
  <?= Form::select('timezone', 'Display timezone', array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()), $s('timezone'), ['hint' => 'Data is stored in UTC; shown in this timezone.']) ?></div>
  <?= Form::input('site_tagline', 'Tagline', $s('site_tagline')) ?>
  <?= Form::input('footer_text', 'Footer text', $s('footer_text')) ?>
  <div class="form-grid">
    <div class="field"><label>Logo</label><?php if ($s('site_logo')): ?><div class="mb-1"><img src="<?= e(upload_url($s('site_logo'))) ?>" alt="" style="max-height:40px" height="40" width="140"></div><?= Form::check('remove_logo', 'Remove', false) ?><?php endif ?><input class="input mt-1" type="file" name="logo" accept="image/png,image/jpeg,image/webp"></div>
    <div class="field"><label>Favicon</label><?php if ($s('site_favicon')): ?><div class="mb-1"><img src="<?= e(upload_url($s('site_favicon'))) ?>" alt="" width="32" height="32"></div><?= Form::check('remove_favicon', 'Remove', false) ?><?php endif ?><input class="input mt-1" type="file" name="favicon" accept="image/png"></div>
  </div>
  <?= Form::toggle('blog_enabled', 'Blog enabled', $on('blog_enabled')) ?>
  <hr><?= Form::toggle('maintenance_mode', 'Maintenance mode (admins can still browse)', $on('maintenance_mode')) ?>
  <?= Form::textarea('maintenance_message', 'Maintenance message', $s('maintenance_message'), ['rows' => 2]) ?>
<?php elseif ($tab === 'contact'): ?>
  <div class="form-grid"><?= Form::input('contact_email', 'Contact email (receives contact form)', $s('contact_email'), ['type' => 'email']) ?><?= Form::input('contact_telegram', 'Telegram', $s('contact_telegram')) ?>
  <?= Form::input('contact_whatsapp', 'WhatsApp', $s('contact_whatsapp')) ?><?= Form::input('contact_address', 'Address', $s('contact_address')) ?>
  <?php foreach (['social_facebook' => 'Facebook URL', 'social_instagram' => 'Instagram URL', 'social_x' => 'X / Twitter URL', 'social_youtube' => 'YouTube URL', 'social_telegram' => 'Telegram channel URL'] as $k => $l): ?><?= Form::input($k, $l, $s($k), ['type' => 'url']) ?><?php endforeach ?></div>
<?php elseif ($tab === 'currency'): ?>
  <div class="alert alert-warning"><?= icon('alert') ?><div>The currency applies to all balances and prices. Change it only before you have customer balances — existing amounts are not converted.</div></div>
  <div class="form-grid"><?= Form::input('currency_code', 'Currency code (ISO)', $s('currency_code'), ['required' => true, 'maxlength' => 3, 'hint' => 'Sent to gateways, e.g. USD, EUR, INR.']) ?><?= Form::input('currency_symbol', 'Symbol', $s('currency_symbol'), ['required' => true]) ?>
  <?= Form::select('currency_position', 'Symbol position', ['before' => 'Before ($10.00)', 'after' => 'After (10.00 ₹)'], $s('currency_position')) ?><?= Form::select('currency_decimals', 'Decimals shown', ['0' => '0', '2' => '2', '3' => '3', '4' => '4'], $s('currency_decimals')) ?></div>
  <p class="hint">This is the <strong>base currency</strong>: every balance, price, ledger entry and payment is stored and charged in it.</p>
  <?= Form::toggle('currency_switch_enabled', 'Let users choose a display currency', $on('currency_switch_enabled'), 'Display currencies and their rates are managed on the <a href="' . e(admin_url('currencies')) . '">Currencies</a> page. Prices are converted for display only; nothing stored is converted.') ?>
<?php elseif ($tab === 'users'): ?>
  <?= Form::toggle('registration_enabled', 'Allow new registrations', $on('registration_enabled')) ?>
  <?= Form::toggle('email_verification', 'Require email verification (configure SMTP first)', $on('email_verification'), 'New accounts must confirm their email (links expire after 48 hours; users can resend). Accounts that existed before you switch this on are not blocked, unless they change their email.' . ($on('email_verification') && $s('email_verification_since') ? ' Enforced for accounts created since ' . e(fmt_date($s('email_verification_since'))) . '.' : '')) ?>
  <?= Form::toggle('registration_mobile', 'Mobile number on registration', $on('registration_mobile'), 'ON: the registration form (and Google sign-up) shows a mobile number field, and it is required and validated (country code, 7–15 digits). OFF: the field is not shown, not required and nothing is stored. Existing accounts without a number keep working.') ?>
  <div class="setting-sub" data-show-if="registration_mobile"><?= Form::toggle('registration_mobile_optional', 'Let users leave the mobile number empty', $on('registration_mobile_optional')) ?></div>
  <?php if (!\App\Services\MailService::isConfigured()): ?><div class="alert alert-warning"><?= icon('alert') ?><div>Email is not configured yet (<a href="<?= e(admin_url('settings/email')) ?>">Email settings</a>). With verification ON, new users cannot receive their verification link.</div></div><?php endif ?>
  <h3 class="mt-3">Sign in with Google</h3>
  <?php $g = \App\Services\GoogleAuthService::config(); ?>
  <?= Form::toggle('google_login_enabled', 'Show "Continue with Google" on the login and registration pages', $on('google_login_enabled')) ?>
  <div class="setting-sub" data-show-if="google_login_enabled">
    <?php if ($g['source'] === 'env'): ?><div class="alert alert-info"><?= icon('info') ?><div>Using <code>GOOGLE_CLIENT_ID</code> / <code>GOOGLE_CLIENT_SECRET</code> from <code>.env</code>; the fields below are ignored.</div></div><?php endif ?>
    <div class="form-grid">
      <?= Form::input('google_client_id', 'Client ID', $s('google_client_id'), ['placeholder' => '1234567890-abc.apps.googleusercontent.com', 'autocomplete' => 'off']) ?>
      <?= Form::input('google_client_secret', 'Client secret', '', ['type' => 'password', 'autocomplete' => 'new-password', 'placeholder' => (string) setting('google_client_secret') !== '' ? '•••••••• (saved — leave empty to keep)' : 'GOCSPX-…', 'hint' => 'Stored encrypted with your APP_KEY; never shown again.']) ?>
    </div>
    <div class="field"><div class="label">Authorized redirect URI (add this exactly in Google Cloud Console)</div><div class="copy-box"><span class="break-words"><?= e($g['redirect_uri']) ?></span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($g['redirect_uri']) ?>" aria-label="Copy redirect URI"><?= icon('copy') ?></button></div></div>
    <p class="hint">Google Cloud Console → APIs &amp; Services → Credentials → Create OAuth client ID (Web application). Full steps: <code>docs/google-login.md</code>.</p>
  </div>
  <div class="form-grid"><?= Form::input('login_max_attempts', 'Failed logins before lockout', $s('login_max_attempts'), ['type' => 'number']) ?><?= Form::input('login_lockout_minutes', 'Lockout minutes', $s('login_lockout_minutes'), ['type' => 'number']) ?></div>
  <?= Form::select('default_price_level', 'Default price level for new users', $levels, $s('default_price_level'), ['empty' => 'None']) ?>
<?php elseif ($tab === 'orders'): ?>
  <?= Form::input('min_order_amount', 'Minimum order amount', $s('min_order_amount'), ['type' => 'number', 'step' => '0.01']) ?>
  <?= Form::toggle('mass_order_enabled', 'Mass order enabled', $on('mass_order_enabled')) ?>
  <?= Form::input('mass_order_max_lines', 'Mass order max lines', $s('mass_order_max_lines'), ['type' => 'number']) ?>
  <?= Form::toggle('order_cancel_enabled', 'Users can request cancellation', $on('order_cancel_enabled')) ?>
  <?= Form::toggle('refill_enabled', 'Refills enabled', $on('refill_enabled')) ?>
  <?= Form::toggle('subscriptions_enabled', 'Auto-subscriptions enabled', $on('subscriptions_enabled'), 'Enable per service with "Allow auto-subscriptions". When off, no new subscriptions are created and cron stops placing deliveries.') ?>
  <?= Form::input('subscription_max_cycles', 'Maximum deliveries per subscription', $s('subscription_max_cycles'), ['type' => 'number', 'min' => 2, 'max' => 1000])  ?>
  <?= Form::input('order_sync_batch', 'Orders synced per cron run', $s('order_sync_batch'), ['type' => 'number', 'hint' => 'Lower this on slow shared hosting.']) ?>
  <h3 class="mt-3" id="price-protection">Provider price protection</h3>
  <p class="hint mt-0">When a provider raises its cost (detected at every provider sync, and checked again whenever an order is placed), the panel must not keep selling below that cost. <strong>Safe price</strong> = provider cost × (1 + required margin) ÷ (1 − the largest price-level or custom discount), so even the most discounted customer pays at least cost + margin. Orders already placed keep their price. Every change is listed on <a href="<?= e(admin_url('services/price-changes')) ?>">Services → Price changes</a>.</p>
  <div class="form-grid">
    <?= Form::select('price_protection_mode', 'When a service is no longer safe', \App\Services\PriceProtection::MODES, \App\Services\PriceProtection::mode()) ?>
    <?= Form::input('price_protection_margin', 'Required margin over provider cost (%)', $s('price_protection_margin'), ['type' => 'number', 'step' => '0.01', 'min' => 0, 'max' => 1000, 'hint' => '0 = never below cost. 10 = at least 10% above cost.']) ?>
  </div>
  <ul class="hint mt-0" style="padding-left:18px">
    <li><strong>Protection only</strong> keeps your prices as they are; unsafe services cannot be ordered until you raise the price (or the cost drops).</li>
    <li><strong>Auto-adjust</strong> raises the price to the safe minimum; prices you set higher are kept and prices are never lowered automatically (only auto-sync services follow the provider down).</li>
    <li><strong>Disable service</strong> switches unsafe services off and back on when they are safe again.</li>
  </ul>
<?php elseif ($tab === 'funds'): ?>
  <div class="alert alert-info"><?= icon('info') ?><div>Minimum and maximum deposits are set <strong>per payment gateway</strong>: <a href="<?= e(admin_url('gateways')) ?>">Payment gateways</a> → edit → <em>Minimum deposit</em>. The deposit page and the server both use the selected gateway's limits.</div></div>
  <?= Form::input('payment_expiry_minutes', 'Invoice expiry (minutes)', $s('payment_expiry_minutes'), ['type' => 'number']) ?>
  <p class="hint">Per-method limits and fees are set in <a href="<?= e(admin_url('gateways')) ?>">Payment gateways</a>.</p>
<?php elseif ($tab === 'referral'): ?>
  <?= Form::toggle('referral_enabled', 'Referral program enabled', $on('referral_enabled')) ?>
  <div class="form-grid"><?= Form::input('referral_percent', 'Commission % of deposits', $s('referral_percent'), ['type' => 'number', 'step' => '0.01']) ?><?= Form::input('referral_min_withdrawal', 'Minimum transfer to balance', $s('referral_min_withdrawal'), ['type' => 'number', 'step' => '0.01']) ?></div>
  <?= Form::toggle('referral_same_ip_block', 'Block referrals from the referrer\'s IP address', $on('referral_same_ip_block')) ?>
<?php elseif ($tab === 'api'): ?>
  <?= Form::toggle('api_enabled', 'Reseller API enabled', $on('api_enabled')) ?>
  <div class="form-grid"><?= Form::input('api_rate_limit', 'Requests allowed…', $s('api_rate_limit'), ['type' => 'number']) ?><?= Form::input('api_rate_window', '…per seconds', $s('api_rate_window'), ['type' => 'number']) ?></div>
<?php else: ?>
  <?= Form::toggle('ticket_attachments', 'Allow attachments (images, PDF, text)', $on('ticket_attachments')) ?>
  <?= Form::input('ticket_max_open', 'Max open tickets per user', $s('ticket_max_open'), ['type' => 'number']) ?>
<?php endif ?>
  <button class="btn btn-primary" type="submit">Save settings</button>
</div></div></form>
