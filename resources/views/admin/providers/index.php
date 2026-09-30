<?php $this->extend('layouts/admin');
use App\Services\ProviderSyncService;
$states = [
    'ok' => ['Connected', 'badge-success'],
    'error' => ['Error', 'badge-danger'],
    'never' => ['Never checked', 'badge-muted'],
    'syncing' => ['Syncing…', 'badge-info'],
    'disabled' => ['Disabled', 'badge-muted'],
]; ?>
<div class="page-head"><div><h1>Providers</h1><p>SMM API providers that fulfil your orders.</p></div>
  <div class="btn-group"><a class="btn btn-secondary" href="<?= e(admin_url('providers/logs')) ?>"><?= icon('file') ?> API logs</a>
  <form method="post" action="<?= e(admin_url('providers/sync-prices')) ?>"><?= csrf_field() ?><button class="btn btn-secondary" type="submit"><?= icon('refresh') ?> Sync prices</button></form>
  <a class="btn btn-primary" href="<?= e(admin_url('providers/create')) ?>"><?= icon('plus') ?> Add provider</a></div></div>
<?php if (!$providers): ?><div class="card"><div class="empty"><?= icon('server') ?><h3>No providers yet</h3><p>Add the API URL and key from your SMM provider. Most providers use the standard API v2 format.</p><a class="btn btn-primary mt-1" href="<?= e(admin_url('providers/create')) ?>">Add provider</a></div></div><?php endif ?>
<div class="grid-2">
<?php foreach ($providers as $p): $state = ProviderSyncService::displayStatus($p); [$label, $cls] = $states[$state]; ?>
  <div class="card provider-card" data-provider-state="<?= e($state) ?>"><div class="card-header"><div class="min-w-0"><h2 class="truncate"><?= e($p['name']) ?></h2><div class="cell-sub truncate"><?= e($p['api_url']) ?></div></div><span class="badge <?= $cls ?>"><?= e($label) ?></span></div>
    <div class="card-body"><dl class="dl dl-provider">
      <dt>Balance</dt><dd><?= $p['balance'] !== null ? e(\App\Core\Money::format($p['balance'])) . ' ' . e($p['currency']) : '—' ?></dd>
      <dt>Exchange rate</dt><dd>1 <?= e($p['currency']) ?> = <?= e(rtrim(rtrim($p['exchange_rate'], '0'), '.')) ?> <?= e(setting('currency_code', 'USD')) ?></dd>
      <dt>Services</dt><dd><?= (int) $p['services'] ?> linked · <?= (int) $p['catalog'] ?> in catalog</dd>
      <dt>Last check</dt><dd><?= $p['last_checked_at'] ? e(time_ago($p['last_checked_at'])) : 'Never' ?></dd>
      <dt>Catalog synced</dt><dd><?= $state === 'syncing' ? 'In progress (started ' . e(time_ago($p['syncing_since'])) . ')' : ($p['last_synced_at'] ? e(time_ago($p['last_synced_at'])) : 'Never') ?></dd>
    </dl>
    <?php if ($state === 'error' && $p['last_error']): ?>
      <div class="alert alert-danger mt-2 mb-0"><?= icon('alert') ?><div class="min-w-0"><div class="alert-title">Last error</div><div class="text-sm break-words"><?= e($p['last_error']) ?></div>
        <a class="text-sm" href="<?= e(admin_url('providers/logs?provider=' . $p['id'] . '&errors=1')) ?>">View the full response in the API log</a></div></div>
    <?php elseif ($state === 'never'): ?>
      <div class="alert alert-info mt-2 mb-0"><?= icon('info') ?><div>Not checked yet. Use <strong>Test connection</strong> to verify the URL and key.</div></div>
    <?php endif ?>
    </div>
    <div class="card-footer btn-group">
      <form method="post" action="<?= e(admin_url('providers/' . $p['id'] . '/check')) ?>"><?= csrf_field() ?><button class="btn btn-secondary btn-sm" type="submit">Test connection</button></form>
      <form method="post" action="<?= e(admin_url('providers/' . $p['id'] . '/fetch')) ?>"><?= csrf_field() ?><button class="btn btn-secondary btn-sm" type="submit">Fetch services</button></form>
      <a class="btn btn-soft btn-sm" href="<?= e(admin_url('providers/' . $p['id'] . '/services')) ?>">Browse & import</a>
      <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('providers/' . $p['id'] . '/edit')) ?>"><?= icon('edit') ?> Edit</a>
    </div></div>
<?php endforeach ?>
</div>
