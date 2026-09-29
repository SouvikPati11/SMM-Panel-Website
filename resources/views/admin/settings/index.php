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
<?php elseif ($tab === 'users'): ?>
  <?= Form::toggle('registration_enabled', 'Allow new registrations', $on('registration_enabled')) ?>
  <?= Form::toggle('email_verification', 'Require email verification (configure SMTP first)', $on('email_verification')) ?>
  <div class="form-grid"><?= Form::input('login_max_attempts', 'Failed logins before lockout', $s('login_max_attempts'), ['type' => 'number']) ?><?= Form::input('login_lockout_minutes', 'Lockout minutes', $s('login_lockout_minutes'), ['type' => 'number']) ?></div>
  <?= Form::select('default_price_level', 'Default price level for new users', $levels, $s('default_price_level'), ['empty' => 'None']) ?>
<?php elseif ($tab === 'orders'): ?>
  <?= Form::input('min_order_amount', 'Minimum order amount', $s('min_order_amount'), ['type' => 'number', 'step' => '0.01']) ?>
  <?= Form::toggle('mass_order_enabled', 'Mass order enabled', $on('mass_order_enabled')) ?>
  <?= Form::input('mass_order_max_lines', 'Mass order max lines', $s('mass_order_max_lines'), ['type' => 'number']) ?>
  <?= Form::toggle('order_cancel_enabled', 'Users can request cancellation', $on('order_cancel_enabled')) ?>
  <?= Form::toggle('refill_enabled', 'Refills enabled', $on('refill_enabled')) ?>
  <?= Form::input('order_sync_batch', 'Orders synced per cron run', $s('order_sync_batch'), ['type' => 'number', 'hint' => 'Lower this on slow shared hosting.']) ?>
<?php elseif ($tab === 'funds'): ?>
  <div class="form-grid"><?= Form::input('min_deposit', 'Minimum deposit', $s('min_deposit'), ['type' => 'number', 'step' => '0.01']) ?><?= Form::input('max_deposit', 'Maximum deposit', $s('max_deposit'), ['type' => 'number', 'step' => '0.01']) ?></div>
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
