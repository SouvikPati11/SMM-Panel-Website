<?php $this->extend('layouts/admin'); use App\Helpers\Form; $s = $service ?? []; ?>
<div class="page-head"><div><ol class="breadcrumb"><li><a href="<?= e(admin_url('services')) ?>">Services</a></li><li><?= $service ? '#' . (int) $s['id'] : 'New' ?></li></ol><h1><?= e($title) ?></h1><?php if ($service): ?><p><?= number_format($orders) ?> orders placed</p><?php endif ?></div>
  <?php if ($service): ?><form method="post" action="<?= e(admin_url('services/' . $s['id'] . '/delete')) ?>" data-confirm="Delete this service? If it has orders it will be disabled instead."><?= csrf_field() ?><button class="btn btn-ghost" type="submit"><?= icon('trash') ?> Delete</button></form><?php endif ?></div>
<form method="post" action="<?= e(admin_url('services/save')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($s['id'] ?? 0) ?>">
<div class="grid-main">
  <div>
    <div class="card mb-2"><div class="card-header"><h2>Basics</h2></div><div class="card-body">
      <?= Form::input('name', 'Name', $s['name'] ?? '', ['required' => true, 'maxlength' => 255]) ?>
      <div class="form-grid">
        <?= Form::select('category_id', 'Category', $categories, (string) ($s['category_id'] ?? ''), ['required' => true]) ?>
        <?= Form::select('type', 'Service type', $types, $s['type'] ?? 'default', ['hint' => 'Controls which fields the order form shows.']) ?>
      </div>
      <?= Form::textarea('description', 'Description (shown on the order form)', $s['description'] ?? '', ['rows' => 6, 'hint' => 'Plain text. Mention start time, speed, quality, requirements.']) ?>
      <div class="form-grid">
        <?= Form::input('link_label', 'Link field label', $s['link_label'] ?? 'Link', ['hint' => 'e.g. Link, Username, Post URL, Video URL']) ?>
        <?= Form::select('link_type', 'Link validation', ['url' => 'Must be an http(s) URL', 'username' => 'Username (no spaces)', 'text' => 'Any text (no spaces)'], $custom['link_type'] ?? 'url') ?>
      </div>
      <?= Form::input('link_regex', 'Link pattern (optional regex)', $custom['link_regex'] ?? '', ['placeholder' => '#^https://(www\.)?instagram\.com/#i', 'hint' => 'Rejects links that do not match, e.g. to accept only Instagram URLs.']) ?>
      <?= Form::input('average_time', 'Average time (display)', $s['average_time'] ?? '', ['placeholder' => 'e.g. 0-2 hours']) ?>
    </div></div>
    <div class="card"><div class="card-header"><h2>Fulfilment</h2></div><div class="card-body">
      <div class="form-grid">
        <?= Form::select('provider_id', 'Provider', $providers, (string) ($s['provider_id'] ?? ''), ['empty' => 'Manual fulfilment (no API)']) ?>
        <?= Form::input('provider_service_id', 'Provider service ID', $s['provider_service_id'] ?? '', ['hint' => 'The ID of this service in the provider\'s catalog.']) ?>
      </div>
      <p class="hint">Manual services stay "pending" until an admin updates the order. Provider services are sent automatically.</p>
    </div></div>
  </div>
  <div>
    <div class="card mb-2"><div class="card-header"><h2>Pricing & limits</h2></div><div class="card-body">
      <?= Form::input('rate', 'Price per 1000 (packages: per package)', $s['rate'] ?? '', ['type' => 'number', 'step' => '0.000001', 'min' => 0, 'required' => true]) ?>
      <div class="form-grid">
        <?= Form::input('provider_rate', 'Provider cost / 1000', $s['provider_rate'] ?? '', ['type' => 'number', 'step' => '0.000001', 'min' => 0, 'hint' => 'In site currency.']) ?>
        <?= Form::input('markup_percent', 'Markup %', $s['markup_percent'] ?? '', ['type' => 'number', 'step' => '0.01']) ?>
      </div>
      <?= Form::toggle('auto_sync', 'Auto-sync price & limits from provider', (int) ($s['auto_sync'] ?? 0) === 1, 'Rate = provider rate × exchange rate × (1 + markup%). Updated by cron.') ?>
      <div class="form-grid">
        <?= Form::input('min_quantity', 'Min quantity', $s['min_quantity'] ?? 10, ['type' => 'number', 'min' => 1, 'required' => true]) ?>
        <?= Form::input('max_quantity', 'Max quantity', $s['max_quantity'] ?? 100000, ['type' => 'number', 'min' => 1, 'required' => true]) ?>
      </div>
    </div></div>
    <div class="card mb-2"><div class="card-header"><h2>Options</h2></div><div class="card-body">
      <?= Form::toggle('refill', 'Refill available', (int) ($s['refill'] ?? 0) === 1) ?>
      <?= Form::input('refill_days', 'Refill period (days)', $s['refill_days'] ?? 30, ['type' => 'number', 'min' => 0]) ?>
      <?= Form::toggle('cancel', 'Cancel available', (int) ($s['cancel'] ?? 0) === 1) ?>
      <?= Form::toggle('dripfeed', 'Drip-feed available', (int) ($s['dripfeed'] ?? 0) === 1) ?>
      <hr>
      <?= Form::select('status', 'Status', ['active' => 'Active', 'disabled' => 'Disabled'], $s['status'] ?? 'active') ?>
      <?= Form::toggle('is_hidden', 'Hidden from users (API & catalog)', (int) ($s['is_hidden'] ?? 0) === 1) ?>
      <?= Form::input('sort_order', 'Sort order', $s['sort_order'] ?? 0, ['type' => 'number']) ?>
    </div></div>
    <button class="btn btn-primary btn-lg btn-block" type="submit">Save service</button>
  </div>
</div>
</form>
