<?php $this->extend('layouts/admin'); use App\Helpers\Form; $p = $provider ?? []; ?>
<div class="page-head"><div><ol class="breadcrumb"><li><a href="<?= e(admin_url('providers')) ?>">Providers</a></li><li><?= $provider ? e($p['name']) : 'New' ?></li></ol><h1><?= e($title) ?></h1></div>
  <?php if ($provider): ?><form method="post" action="<?= e(admin_url('providers/' . $p['id'] . '/delete')) ?>" data-confirm="Delete this provider? Linked services become manual and disabled."><?= csrf_field() ?><button class="btn btn-ghost" type="submit"><?= icon('trash') ?> Delete</button></form><?php endif ?></div>
<form method="post" action="<?= e(admin_url('providers/save')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($p['id'] ?? 0) ?>">
<div class="grid-main">
  <div class="card"><div class="card-body">
    <?= Form::input('name', 'Name', $p['name'] ?? '', ['required' => true]) ?>
    <?= Form::input('api_url', 'API URL', $p['api_url'] ?? '', ['required' => true, 'placeholder' => 'https://provider.example/api/v2', 'hint' => 'Exactly as shown in your provider\'s API documentation. HTTPS required.']) ?>
    <?= Form::input('api_key', 'API key', '', ['type' => 'password', 'autocomplete' => 'off', 'required' => !$provider, 'hint' => $provider ? 'Stored encrypted. Current: <code>' . e($maskedKey) . '</code> — leave empty to keep.' : 'Stored encrypted with your APP_KEY; never shown again.']) ?>
    <div class="form-grid">
      <?= Form::select('adapter', 'API format', array_map(fn ($a) => $a['label'], $adapters), $p['adapter'] ?? 'standard_v2') ?>
      <?= Form::input('timeout', 'Timeout (seconds)', $p['timeout'] ?? 30, ['type' => 'number', 'min' => 5, 'max' => 120]) ?>
      <?= Form::input('currency', 'Provider currency', $p['currency'] ?? 'USD', ['maxlength' => 3]) ?>
      <?= Form::input('exchange_rate', 'Exchange rate to site currency', $p['exchange_rate'] ?? '1', ['type' => 'number', 'step' => '0.00000001', 'hint' => '1 provider-currency unit = X site-currency units. Used when importing & syncing prices.']) ?>
    </div>
    <?= Form::select('status', 'Status', ['active' => 'Active', 'disabled' => 'Disabled (orders stay queued)'], $p['status'] ?? 'active') ?>
    <?= Form::textarea('config', 'Advanced: adapter configuration (JSON, optional)', $config, ['rows' => 8, 'textarea_class' => 'code', 'hint' => 'Only needed if the provider deviates from the standard (different parameter/action names, no multi-status, GET method). See docs/provider-api.md.']) ?>
    <button class="btn btn-primary" type="submit">Save & test connection</button>
  </div></div>
  <div class="card"><div class="card-body text-sm">
    <h3>Standard API v2</h3>
    <p>Requests are <code>POST</code> form fields <code>key</code> + <code>action</code>:</p>
    <ul style="padding-left:18px"><li><code>services</code> — catalog</li><li><code>add</code> — new order</li><li><code>status</code> — single / multi (<code>orders=1,2</code>)</li><li><code>refill</code>, <code>refill_status</code></li><li><code>cancel</code></li><li><code>balance</code></li></ul>
    <p class="mb-0">Example config for a panel that names the key <code>api_token</code> and lacks multi-status:</p>
    <pre><code>{"key_param":"api_token","multi_status":false}</code></pre>
  </div></div>
</div></form>
