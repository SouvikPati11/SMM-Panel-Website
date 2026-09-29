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
      <dt>Price level</dt><dd><?= e(db()->fetchColumn('SELECT name FROM price_levels WHERE id = ?', [(int) $user['price_level_id']]) ?: 'Standard') ?></dd>
      <dt>Last login</dt><dd><?= e(fmt_date($user['last_login_at'])) ?></dd>
    </dl>
  </div></div>
</div>
