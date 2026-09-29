<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>Providers</h1><p>SMM API providers that fulfil your orders.</p></div>
  <div class="btn-group"><a class="btn btn-secondary" href="<?= e(admin_url('providers/logs')) ?>"><?= icon('file') ?> API logs</a>
  <form method="post" action="<?= e(admin_url('providers/sync-prices')) ?>"><?= csrf_field() ?><button class="btn btn-secondary" type="submit"><?= icon('refresh') ?> Sync prices</button></form>
  <a class="btn btn-primary" href="<?= e(admin_url('providers/create')) ?>"><?= icon('plus') ?> Add provider</a></div></div>
<?php if (!$providers): ?><div class="card"><div class="empty"><?= icon('server') ?><h3>No providers yet</h3><p>Add the API URL and key from your SMM provider. Most providers use the standard API v2 format.</p><a class="btn btn-primary mt-1" href="<?= e(admin_url('providers/create')) ?>">Add provider</a></div></div><?php endif ?>
<div class="grid-2">
<?php foreach ($providers as $p): ?>
  <div class="card"><div class="card-header"><div><h2><?= e($p['name']) ?></h2><div class="cell-sub truncate" style="max-width:320px"><?= e($p['api_url']) ?></div></div><?= $p['status'] === 'disabled' ? status_badge('disabled') : status_badge($p['connection_status']) ?></div>
    <div class="card-body"><dl class="dl" style="grid-template-columns:140px 1fr">
      <dt>Balance</dt><dd><?= $p['balance'] !== null ? e(\App\Core\Money::format($p['balance'])) . ' ' . e($p['currency']) : '—' ?></dd>
      <dt>Exchange rate</dt><dd>1 <?= e($p['currency']) ?> = <?= e(rtrim(rtrim($p['exchange_rate'], '0'), '.')) ?> <?= e(setting('currency_code', 'USD')) ?></dd>
      <dt>Services</dt><dd><?= (int) $p['services'] ?> linked · <?= (int) $p['catalog'] ?> in catalog</dd>
      <dt>Last check</dt><dd><?= e(time_ago($p['last_checked_at'])) ?></dd>
      <dt>Catalog synced</dt><dd><?= e(time_ago($p['last_synced_at'])) ?></dd>
      <?php if ($p['last_error']): ?><dt>Last error</dt><dd class="text-danger text-sm"><?= e($p['last_error']) ?></dd><?php endif ?>
    </dl></div>
    <div class="card-footer btn-group">
      <form method="post" action="<?= e(admin_url('providers/' . $p['id'] . '/check')) ?>"><?= csrf_field() ?><button class="btn btn-secondary btn-sm" type="submit">Test connection</button></form>
      <form method="post" action="<?= e(admin_url('providers/' . $p['id'] . '/fetch')) ?>"><?= csrf_field() ?><button class="btn btn-secondary btn-sm" type="submit">Fetch services</button></form>
      <a class="btn btn-soft btn-sm" href="<?= e(admin_url('providers/' . $p['id'] . '/services')) ?>">Browse & import</a>
      <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('providers/' . $p['id'] . '/edit')) ?>"><?= icon('edit') ?> Edit</a>
    </div></div>
<?php endforeach ?>
</div>
