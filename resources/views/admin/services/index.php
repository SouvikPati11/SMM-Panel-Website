<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>Services</h1><p><?= number_format($services->total) ?> services</p></div>
  <div class="btn-group"><a class="btn btn-secondary" href="<?= e(admin_url('categories')) ?>"><?= icon('tag') ?> Categories</a><?php if (can('providers.manage')): ?><a class="btn btn-secondary" href="<?= e(admin_url('providers')) ?>"><?= icon('server') ?> Import from provider</a><?php endif ?><a class="btn btn-primary" href="<?= e(admin_url('services/create')) ?>"><?= icon('plus') ?> Add service</a></div></div>
<form class="toolbar" method="get" action="<?= e(admin_url('services')) ?>">
  <input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, ID or provider service ID">
  <select class="select" name="category" data-autosubmit><option value="">All categories</option><?php foreach ($categories as $id => $n): ?><option value="<?= (int) $id ?>"<?= $f['category'] === (int) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach ?></select>
  <select class="select" name="provider" data-autosubmit><option value="">All providers</option><option value="manual"<?= $f['provider'] === 'manual' ? ' selected' : '' ?>>Manual</option><?php foreach ($providers as $id => $n): ?><option value="<?= (int) $id ?>"<?= $f['provider'] === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach ?></select>
  <select class="select" name="type" data-autosubmit aria-label="Service type"><option value="">All types</option><?php foreach ($types as $k => $label): ?><option value="<?= e($k) ?>"<?= $f['type'] === $k ? ' selected' : '' ?>><?= e($k === 'subscription' ? 'Subscriptions (incl. services that allow them)' : $label) ?></option><?php endforeach ?></select>
  <select class="select" name="status" data-autosubmit><option value="">Any status</option><option value="active"<?= $f['status'] === 'active' ? ' selected' : '' ?>>Active</option><option value="disabled"<?= $f['status'] === 'disabled' ? ' selected' : '' ?>>Disabled</option><option value="hidden"<?= $f['status'] === 'hidden' ? ' selected' : '' ?>>Hidden</option></select>
  <button class="btn btn-secondary" type="submit"><?= icon('search') ?></button>
</form>
<form method="post" action="<?= e(admin_url('services/bulk')) ?>" data-confirm="Apply this bulk action to the selected services?">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-header">
      <div class="flex gap-1 wrap items-center">
        <select class="select" name="action" style="min-height:36px;width:auto"><option value="">Bulk action…</option><option value="enable">Enable</option><option value="disable">Disable</option><option value="hide">Hide from users</option><option value="unhide">Unhide</option><option value="category">Move to category</option><option value="markup">Set markup % over provider rate</option><option value="adjust">Adjust price by ± %</option><option value="delete">Delete</option></select>
        <select class="select" name="category_id" style="min-height:36px;width:auto"><option value="">(category)</option><?php foreach ($categories as $id => $n): ?><option value="<?= (int) $id ?>"><?= e($n) ?></option><?php endforeach ?></select>
        <input class="input" name="value" placeholder="% value" style="min-height:36px;width:110px">
        <button class="btn btn-secondary btn-sm" type="submit">Apply</button>
      </div>
    </div>
    <?php if (!$services->items): ?><div class="empty"><?= icon('layers') ?><h3>No services</h3><p>Add one manually or import from a provider.</p></div><?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
      <thead><tr><th><input type="checkbox" data-check-all=".svc-check" aria-label="Select all"></th><th>ID</th><th>Service</th><th>Category</th><th class="num">Rate</th><th class="num">Cost</th><th class="num">Min / Max</th><th>Provider</th><th>Status</th><th></th></tr></thead>
      <tbody><?php foreach ($services->items as $s): ?>
        <tr><td data-label="Select"><input class="svc-check" type="checkbox" name="ids[]" value="<?= (int) $s['id'] ?>"></td>
          <td data-label="ID" class="mono"><?= (int) $s['id'] ?></td>
          <td class="cell-main"><a class="cell-title" href="<?= e(admin_url('services/' . $s['id'] . '/edit')) ?>"><?= e(str_limit($s['name'], 70)) ?></a><?php if ($s['type'] === 'subscription'): ?> <span class="badge badge-purple no-dot"><?= icon('refresh') ?> Subscription</span><?php elseif ((int) $s['subscription_enabled'] === 1): ?> <span class="badge badge-info no-dot" title="Can also be ordered as an auto-subscription"><?= icon('refresh') ?> Sub. allowed</span><?php endif ?><div class="cell-sub"><?= e(\App\Services\OrderService::TYPES[$s['type']]['label'] ?? $s['type']) ?><?= $s['refill'] ? ' · refill' : '' ?><?= $s['cancel'] ? ' · cancel' : '' ?><?= $s['dripfeed'] ? ' · drip' : '' ?><?= $s['auto_sync'] ? ' · auto-sync' : '' ?></div></td>
          <td data-label="Category" class="text-sm"><?= e($s['category']) ?></td>
          <td data-label="Rate" class="num fw-bold"><?= e(rate($s['rate'])) ?></td>
          <td data-label="Cost" class="num text-muted"><?= $s['provider_rate'] !== null ? e(rate($s['provider_rate'])) : '—' ?></td>
          <td data-label="Min / Max" class="num text-sm nowrap"><?= number_format((int) $s['min_quantity']) ?> / <?= number_format((int) $s['max_quantity']) ?></td>
          <td data-label="Provider" class="text-sm"><?= e($s['provider'] ?: 'Manual') ?><?= $s['provider_service_id'] ? ' <span class="mono text-muted">#' . e($s['provider_service_id']) . '</span>' : '' ?></td>
          <td data-label="Status"><?= status_badge($s['status']) ?><?= (int) $s['is_hidden'] ? ' ' . status_badge('hidden') : '' ?></td>
          <td class="actions"><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('services/' . $s['id'] . '/edit')) ?>"><?= icon('edit') ?></a></td></tr>
      <?php endforeach ?></tbody></table></div>
    <?= $services->links(\App\Core\App::request()) ?>
    <?php endif ?>
  </div>
</form>
