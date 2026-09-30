<?php $this->extend('layouts/admin'); use App\Helpers\Form; $m = $method; $isManual = $m['gateway'] === 'manual'; ?>
<div class="page-head"><div><ol class="breadcrumb"><li><a href="<?= e(admin_url('gateways')) ?>">Payment gateways</a></li><li><?= e($m['name'] ?? 'New') ?></li></ol><h1><?= e($title) ?></h1></div>
  <?php if ($isManual && !empty($m['id'])): ?><form method="post" action="<?= e(admin_url('gateways/' . $m['id'] . '/delete')) ?>" data-confirm="Delete this payment method?"><?= csrf_field() ?><button class="btn btn-ghost" type="submit"><?= icon('trash') ?> Delete</button></form><?php endif ?></div>
<?php if ($gateway && !$gateway->isImplemented()): ?>
<div class="alert alert-warning"><?= icon('alert') ?><div><div class="alert-title">Not implemented</div>No official API documentation for this gateway was available, so no endpoints, parameters or signature checks have been invented. You can store credentials now; the method cannot be enabled until the adapter is completed. See <code>docs/payment-gateways.md</code> for exactly what is needed.</div></div>
<?php endif ?>
<form method="post" action="<?= e(admin_url('gateways/save')) ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($m['id'] ?? 0) ?>">
<div class="grid-main">
  <div>
    <div class="card mb-2"><div class="card-header"><h2>Display & limits</h2></div><div class="card-body">
      <?= Form::input('name', 'Name shown to users', $m['name'] ?? '', ['required' => true]) ?>
      <?= Form::textarea('instructions', 'Instructions', $m['instructions'] ?? '', ['rows' => 5, 'hint' => 'Plain text shown on the Add Funds page.']) ?>
      <div class="form-grid">
        <?= Form::input('min_amount', 'Minimum deposit (' . e(setting('currency_code', 'USD')) . ')', isset($m['min_amount']) ? rtrim(rtrim((string) $m['min_amount'], '0'), '.') : '1', ['type' => 'number', 'step' => '0.01', 'min' => '0.01', 'required' => true, 'hint' => 'Enforced on the server for this gateway only.']) ?>
        <?= Form::input('max_amount', 'Maximum deposit (' . e(setting('currency_code', 'USD')) . ')', isset($m['max_amount']) ? rtrim(rtrim((string) $m['max_amount'], '0'), '.') : '10000', ['type' => 'number', 'step' => '0.01', 'min' => '0.01', 'required' => true]) ?>
        <?= Form::input('fee_percent', 'Fee % (added on top)', $m['fee_percent'] ?? '0', ['type' => 'number', 'step' => '0.01', 'min' => 0]) ?>
        <?= Form::input('sort_order', 'Sort order', $m['sort_order'] ?? 0, ['type' => 'number']) ?>
      </div>
      <?= Form::select('status', 'Status', ['active' => 'Active', 'disabled' => 'Disabled'], $m['status'] ?? 'disabled') ?>
    </div></div>
    <div class="card mb-2" id="deposit-bonus"><div class="card-header"><h2>Deposit bonus</h2></div><div class="card-body">
      <p class="text-sm text-muted">Extra balance credited automatically when a deposit through <strong>this gateway only</strong> is confirmed. Calculated on the server from the confirmed amount; shown to users before they pay. Leave both at 0 for no bonus.</p>
      <div class="form-grid">
        <?= Form::input('bonus_percent', 'Bonus %', isset($m['bonus_percent']) ? rtrim(rtrim((string) $m['bonus_percent'], '0'), '.') ?: '0' : '0', ['type' => 'number', 'step' => '0.01', 'min' => 0, 'max' => 100, 'hint' => 'Percent of the deposit.']) ?>
        <?= Form::input('bonus_fixed', 'Fixed bonus (' . e(setting('currency_code', 'USD')) . ')', isset($m['bonus_fixed']) ? rtrim(rtrim((string) $m['bonus_fixed'], '0'), '.') ?: '0' : '0', ['type' => 'number', 'step' => '0.01', 'min' => 0, 'hint' => 'Added to every qualifying deposit.']) ?>
        <?= Form::input('bonus_min_amount', 'Minimum deposit for bonus', \App\Core\Money::isPositive((string) ($m['bonus_min_amount'] ?? '0')) ? rtrim(rtrim((string) $m['bonus_min_amount'], '0'), '.') : '', ['type' => 'number', 'step' => '0.01', 'min' => 0, 'hint' => 'Empty or 0 = every deposit qualifies.']) ?>
      </div>
    </div></div>
    <?php if ($isManual): ?>
    <div class="card"><div class="card-header"><h2>Manual payment details</h2></div><div class="card-body">
      <?= Form::input('account', 'Account / UPI ID / wallet address', $m['account'] ?? '', ['hint' => 'Shown with a copy button.']) ?>
      <div class="field"><label>QR code image</label><?php if (!empty($m['qr_image'])): ?><div class="mb-1"><img src="<?= e(upload_url($m['qr_image'])) ?>" alt="QR" width="140" height="140" style="border:1px solid var(--border);border-radius:8px"></div><?= Form::check('remove_qr', 'Remove current image', false) ?><?php endif ?><input class="input mt-1" type="file" name="qr_image" accept="image/png,image/jpeg,image/webp"></div>
      <?= Form::toggle('require_proof', 'Require payment screenshot', (int) ($m['require_proof'] ?? 0) === 1) ?>
    </div></div>
    <?php endif ?>
  </div>
  <div>
    <?php if ($gateway): ?>
    <div class="card mb-2"><div class="card-header"><h2>API credentials</h2><span class="badge badge-muted no-dot"><?= icon('lock') ?> encrypted</span></div><div class="card-body">
      <?php foreach ($gateway->credentialFields() as $f): ?>
        <?= Form::input('cred_' . $f['name'], $f['label'], '', ['type' => $f['secret'] ? 'password' : 'text', 'autocomplete' => 'off', 'placeholder' => $creds[$f['name']] ?? '', 'hint' => e($f['help'] ?? '') . (($creds[$f['name']] ?? '') !== '' ? ' <br>Stored — leave empty to keep.' : '')]) ?>
      <?php endforeach ?>
    </div></div>
    <?php if ($gateway->optionFields()): ?>
    <div class="card mb-2"><div class="card-header"><h2>Options</h2></div><div class="card-body">
      <?php foreach ($gateway->optionFields() as $f): $v = $options[$f['name']] ?? $f['default']; ?>
        <?php if ($f['type'] === 'bool'): ?><?= Form::select('opt_' . $f['name'], $f['label'], ['0' => 'No', '1' => 'Yes'], (string) $v, ['hint' => e($f['help'] ?? '')]) ?>
        <?php else: ?><?= Form::input('opt_' . $f['name'], $f['label'], $v, ['type' => $f['type'] === 'number' ? 'number' : 'text', 'hint' => e($f['help'] ?? '')]) ?><?php endif ?>
      <?php endforeach ?>
    </div></div>
    <?php endif ?>
    <?php if (!$gateway->callbacksAreSigned()): ?><div class="alert alert-info"><?= icon('shield') ?><div>This gateway's callbacks are not signed (no signature is documented). Callbacks are only used as a trigger: every payment is confirmed with the gateway's status API, and the amount must match exactly, before anything is credited.</div></div><?php endif ?>
    <?php if (!str_starts_with(url('/'), 'https://')): ?><div class="alert alert-warning"><?= icon('alert') ?><div>Your APP_URL is not HTTPS. Gateways require an HTTPS webhook URL in production.</div></div><?php endif ?>
    <div class="card mb-2"><div class="card-body text-sm">Webhook / callback URL<?= $m['gateway'] === 'p2gateway' ? ' — paste into <strong>P2Gateway Merchant Dashboard → Webhook URL</strong>' : '' ?>:<div class="copy-box mt-1"><span><?= e(url('/webhooks/' . $m['gateway'])) ?></span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e(url('/webhooks/' . $m['gateway'])) ?>"><?= icon('copy') ?></button></div></div></div>
    <?php endif ?>
    <button class="btn btn-primary btn-lg btn-block" type="submit">Save</button>
  </div>
</div></form>
