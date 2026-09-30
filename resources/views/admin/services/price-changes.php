<?php $this->extend('layouts/admin');
$labels = ['increase' => ['Cost up', 'badge-warning'], 'decrease' => ['Cost down', 'badge-info'], 'set' => ['Cost recorded', 'badge-muted'],
    'repriced' => ['Price raised', 'badge-purple'], 'blocked' => ['Ordering blocked', 'badge-danger'], 'disabled' => ['Disabled', 'badge-danger'],
    'unblocked' => ['Unblocked', 'badge-success'], 'restored' => ['Re-enabled', 'badge-success']];
$modeLabel = ['protect' => 'Protection only', 'auto' => 'Auto-adjust', 'disable' => 'Disable service', 'off' => 'Off']; ?>
<div class="page-head"><div><ol class="breadcrumb"><li><a href="<?= e(admin_url('services')) ?>">Services</a></li><li>Price changes</li></ol><h1>Provider price changes</h1>
  <p>Mode <strong><?= e($modeLabel[$mode]) ?></strong> · required margin <strong><?= e(rtrim(rtrim($margin, '0'), '.') ?: '0') ?>%</strong> · largest customer discount <strong><?= e(rtrim(rtrim($maxDiscount, '0'), '.') ?: '0') ?>%</strong></p></div>
  <a class="btn btn-secondary" href="<?= e(admin_url('settings?tab=orders#price-protection')) ?>"><?= icon('settings') ?> Protection settings</a></div>

<div class="card mb-2"><div class="card-header"><h2>Needs attention</h2><span class="badge <?= $alerts ? 'badge-danger' : 'badge-success' ?> no-dot"><?= count($alerts) ?></span></div>
  <?php if (!$alerts): ?><div class="empty" style="padding:24px"><?= icon('check-circle') ?><p class="mb-0">Every provider-linked service sells at or above its safe price.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table table-cards"><thead><tr><th>Service</th><th class="num">Provider cost</th><th class="num">Your price</th><th class="num">Safe minimum</th><th>State</th><th></th></tr></thead><tbody>
    <?php foreach ($alerts as $a): ?><tr>
      <td class="cell-main"><a class="cell-title" href="<?= e(admin_url('services/' . $a['id'] . '/edit')) ?>">#<?= (int) $a['id'] ?> <?= e(str_limit($a['name'], 60)) ?></a><div class="cell-sub"><?= e($a['provider'] ?? '—') ?><?= (int) $a['auto_sync'] ? ' · auto-sync' : '' ?></div></td>
      <td data-label="Provider cost" class="num"><?= e(rate($a['provider_rate'])) ?></td>
      <td data-label="Your price" class="num <?= $a['below'] ? 'text-danger fw-bold' : '' ?>"><?= e(rate($a['rate'])) ?></td>
      <td data-label="Safe minimum" class="num"><?= e(rate($a['safe_rate'])) ?></td>
      <td data-label="State"><?php if ((int) $a['price_disabled']): ?><span class="badge badge-danger">Disabled by protection</span><?php elseif ((int) $a['price_blocked']): ?><span class="badge badge-danger">Ordering blocked</span><?php else: ?><span class="badge badge-warning">Below safe price</span><?php endif ?></td>
      <td class="actions"><a class="btn btn-soft btn-sm" href="<?= e(admin_url('services/' . $a['id'] . '/edit')) ?>">Set price</a></td></tr>
    <?php endforeach ?></tbody></table></div>
  <?php endif ?>
</div>

<div class="card"><div class="card-header"><h2>History</h2></div>
  <?php if (!$events->items): ?><div class="empty" style="padding:24px"><p class="mb-0">No provider cost changes recorded yet. They are recorded at each provider sync (cron, <em>Fetch services</em> or <em>Sync prices</em>).</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table table-cards"><thead><tr><th>When</th><th>Service</th><th>Event</th><th class="num">Cost</th><th class="num">Price</th><th>Details</th></tr></thead><tbody>
    <?php foreach ($events->items as $ev): [$l, $c] = $labels[$ev['action']] ?? [ucfirst($ev['action']), 'badge-muted']; ?><tr>
      <td data-label="When" class="text-sm nowrap"><?= e(fmt_date($ev['created_at'])) ?></td>
      <td class="cell-main"><a class="cell-title" href="<?= e(admin_url('services/' . $ev['service_id'] . '/edit')) ?>">#<?= (int) $ev['service_id'] ?> <?= e(str_limit($ev['service'], 50)) ?></a><div class="cell-sub"><?= e($ev['provider'] ?? '') ?></div></td>
      <td data-label="Event"><span class="badge <?= $c ?>"><?= e($l) ?></span></td>
      <td data-label="Cost" class="num nowrap"><?= $ev['old_cost'] !== null ? e(rate($ev['old_cost'])) . ' → ' : '' ?><?= e(rate($ev['new_cost'])) ?></td>
      <td data-label="Price" class="num nowrap"><?= e(rate($ev['old_rate'])) ?><?= $ev['new_rate'] !== $ev['old_rate'] ? ' → ' . e(rate($ev['new_rate'])) : '' ?></td>
      <td data-label="Details" class="text-sm break-words"><?= e($ev['message']) ?></td></tr>
    <?php endforeach ?></tbody></table></div>
  <?= $events->links(\App\Core\App::request()) ?>
  <?php endif ?>
</div>
