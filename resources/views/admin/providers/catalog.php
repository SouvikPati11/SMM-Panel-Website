<?php $this->extend('layouts/admin'); use App\Helpers\Form; ?>
<div class="page-head"><div><ol class="breadcrumb"><li><a href="<?= e(admin_url('providers')) ?>">Providers</a></li><li><?= e($provider['name']) ?></li></ol><h1><?= e($provider['name']) ?> catalog</h1><p>Rates in <?= e($provider['currency']) ?>. <?= number_format($items->total) ?> services. Last fetched <?= e(time_ago($provider['last_synced_at'])) ?>.</p></div>
  <form method="post" action="<?= e(admin_url('providers/' . $provider['id'] . '/fetch')) ?>"><?= csrf_field() ?><button class="btn btn-secondary" type="submit"><?= icon('refresh') ?> Refresh from provider</button></form></div>
<form class="toolbar" method="get" action="<?= e(admin_url('providers/' . $provider['id'] . '/services')) ?>">
  <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, category or ID">
  <select class="select" name="cat" data-autosubmit><option value="">All provider categories</option><?php foreach ($provCats as $c): ?><option value="<?= e($c) ?>"<?= $cat === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach ?></select>
  <button class="btn btn-secondary" type="submit"><?= icon('search') ?></button>
</form>
<form method="post" action="<?= e(admin_url('providers/' . $provider['id'] . '/import')) ?>"><?= csrf_field() ?>
<div class="card mb-2"><div class="card-body">
  <div class="form-grid">
    <?= Form::select('category_mode', 'Local category', ['provider' => 'Use / create the provider\'s category names', 'fixed' => 'Put all into the category below'], 'provider') ?>
    <?= Form::select('category_id', 'Category (when fixed)', $categories, '', ['empty' => '—']) ?>
    <?= Form::input('markup', 'Markup %', '30', ['type' => 'number', 'step' => '0.01', 'hint' => 'Your price = provider rate × exchange rate × (1 + markup).']) ?>
    <div class="field"><label>&nbsp;</label><?= Form::check('auto_sync', 'Keep prices & limits in sync automatically', true) ?></div>
  </div>
  <button class="btn btn-primary" type="submit"><?= icon('upload') ?> Import selected</button> <span class="hint">Already-imported services are updated, not duplicated.</span>
</div></div>
<div class="card"><div class="table-wrap"><table class="table table-cards">
  <thead><tr><th><input type="checkbox" data-check-all=".ps-check" aria-label="Select all"></th><th>ID</th><th>Name</th><th>Category</th><th>Type</th><th class="num">Rate</th><th class="num">Min / Max</th><th>Local</th></tr></thead><tbody>
  <?php foreach ($items->items as $ps): ?>
    <tr style="<?= (int) $ps['is_available'] ? '' : 'opacity:.55' ?>"><td data-label="Select"><input class="ps-check" type="checkbox" name="ids[]" value="<?= e($ps['provider_service_id']) ?>"></td>
      <td data-label="ID" class="mono"><?= e($ps['provider_service_id']) ?></td>
      <td class="cell-main"><span class="cell-title"><?= e($ps['name']) ?></span><div class="cell-sub"><?= $ps['refill'] ? 'refill · ' : '' ?><?= $ps['cancel'] ? 'cancel · ' : '' ?><?= $ps['dripfeed'] ? 'drip · ' : '' ?><?= (int) $ps['is_available'] ? '' : '<span class="text-danger">no longer offered</span>' ?></div></td>
      <td data-label="Category" class="text-sm"><?= e($ps['category']) ?></td><td data-label="Type" class="text-sm"><?= e($ps['type']) ?></td>
      <td data-label="Rate" class="num"><?= e(\App\Core\Money::formatRate($ps['rate'])) ?></td>
      <td data-label="Min / Max" class="num text-sm nowrap"><?= number_format((int) $ps['min_quantity']) ?> / <?= number_format((int) $ps['max_quantity']) ?></td>
      <td data-label="Local"><?php if ($ps['local_id']): ?><a href="<?= e(admin_url('services/' . $ps['local_id'] . '/edit')) ?>">#<?= (int) $ps['local_id'] ?> · <?= e(rate($ps['local_rate'])) ?></a><?php else: ?><span class="text-muted">—</span><?php endif ?></td></tr>
  <?php endforeach ?><?php if (!$items->items): ?><tr><td colspan="8" class="text-center text-muted" style="padding:28px">No services — click "Refresh from provider".</td></tr><?php endif ?>
</tbody></table></div><?= $items->links(\App\Core\App::request()) ?></div>
</form>
