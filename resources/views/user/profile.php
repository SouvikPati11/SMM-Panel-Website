<?php $this->extend('layouts/user'); use App\Helpers\Form; ?>
<div class="page-head"><div><h1>Profile</h1><p>Member since <?= e(fmt_date($user['created_at'], 'F Y')) ?></p></div></div>
<div class="grid-main">
  <div class="card"><div class="card-body">
    <form method="post" action="<?= e(url('/account')) ?>">
      <?= csrf_field() ?>
      <div class="form-grid">
        <?= Form::input('username_display', 'Username', $user['username'], ['readonly' => true, 'hint' => 'Usernames cannot be changed.']) ?>
        <?= Form::input('name', 'Display name', $user['name'], ['maxlength' => 100]) ?>
      </div>
      <?= Form::input('email', 'Email', $user['email'], ['type' => 'email', 'required' => true, 'hint' => $user['email_verified_at'] ? '<span class="text-success">Verified</span>' : (setting('email_verification', '0') === '1' ? '<span class="text-warning">Not verified</span>' : '')]) ?>
      <?= Form::input('current_password', 'Current password (required only to change email)', '', ['type' => 'password', 'autocomplete' => 'current-password']) ?>
      <?php if ($mobileMode !== 'off'): ?><?= Form::input('mobile', 'Mobile number' . ($mobileMode === 'optional' ? ' (optional)' : ''), (string) $user['mobile'], ['type' => 'tel', 'required' => $mobileMode === 'required', 'autocomplete' => 'tel', 'inputmode' => 'tel', 'maxlength' => 20, 'hint' => 'Include your country code.']) ?><?php endif ?>
      <?php if ($currencies): ?><div class="field"><label for="f_currency">Display currency</label>
        <select class="select" id="f_currency" name="currency">
          <?php $base = \App\Services\CurrencyService::base(); $mine = $user['currency'] ?: $base['code']; foreach ($currencies as $c): ?><option value="<?= e($c['code']) ?>"<?= $c['code'] === $mine ? ' selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name'] . ' (' . $c['symbol'] . ')') ?><?= $c['base'] ? ' · account currency' : '' ?></option><?php endforeach ?>
        </select>
        <div class="hint">Prices and amounts are shown in this currency using the site's exchange rate. Your balance is always kept, charged and paid in <?= e($base['code']) ?>; changing this never converts it.</div></div><?php endif ?>
      <div class="field"><label for="f_timezone">Timezone</label>
        <select class="select" id="f_timezone" name="timezone">
          <option value="">Site default (<?= e(setting('timezone', 'UTC')) ?>)</option>
          <?php foreach ($timezones as $tz): ?><option value="<?= e($tz) ?>"<?= $user['timezone'] === $tz ? ' selected' : '' ?>><?= e($tz) ?></option><?php endforeach ?>
        </select>
      </div>
      <button class="btn btn-primary" type="submit">Save changes</button>
    </form>
  </div></div>
  <div class="card"><div class="card-body">
    <h3>Account summary</h3>
    <dl class="dl" style="grid-template-columns:130px 1fr">
      <dt>Balance</dt><dd><?= e(money($user['balance'])) ?></dd>
      <dt>Total spent</dt><dd><?= e(money($user['total_spent'])) ?></dd>
      <dt>Price level</dt><dd><?= e($level['current']['name'] ?? 'Standard') ?><?= !empty($level['current']) && (float) $level['current']['discount_percent'] > 0 ? ' <span class="badge badge-success no-dot">−' . e(rtrim(rtrim((string) $level['current']['discount_percent'], '0'), '.')) . '%</span>' : '' ?></dd>
      <?php if (!$level['manual'] && $level['next']): ?><dt>Next level</dt><dd><?= e($level['next']['name']) ?> — deposit <?= e(money($level['remaining'])) ?> more<div class="progress mt-1" aria-hidden="true"><span style="width:<?= (int) $level['percent'] ?>%"></span></div></dd><?php endif ?>
      <dt>Last login</dt><dd><?= e(fmt_date($user['last_login_at'])) ?></dd>
    </dl>
  </div></div>
</div>
