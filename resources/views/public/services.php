<?php $this->extend('layouts/public'); ?>
<section class="page-hero">
  <div class="container">
    <ol class="breadcrumb"><li><a href="<?= e(url('/')) ?>">Home</a></li><li>Services</li></ol>
    <h1>Services & pricing</h1>
    <p>All prices are per 1,000 units unless marked as a package. Log in to see prices with your personal discount.</p>
  </div>
</section>
<div class="container">
  <form class="toolbar" method="get" action="<?= e(url('/services')) ?>" role="search">
    <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name or ID" aria-label="Search services">
    <select class="select" name="category" data-autosubmit aria-label="Category">
      <option value="">All categories</option>
      <?php foreach ($categories as $id => $name): ?><option value="<?= (int) $id ?>"<?= (int) $cat === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach ?>
    </select>
    <button class="btn btn-primary" type="submit"><?= icon('search') ?> Search</button>
  </form>

  <?php if (!$grouped): ?>
    <div class="card"><div class="empty"><?= icon('search') ?><h3>No services found</h3><p>Try a different search or category.</p></div></div>
  <?php endif ?>

  <?php foreach ($grouped as $group): ?>
  <div class="card mb-2">
    <div class="card-header"><h2><?= e($group['name']) ?></h2><span class="text-muted text-sm"><?= count($group['services']) ?> services</span></div>
    <div class="table-wrap">
      <table class="table table-cards">
        <thead><tr><th>ID</th><th>Service</th><th class="num">Rate</th><th class="num">Min / Max</th><th>Features</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($group['services'] as $s): $pkg = \App\Services\OrderService::TYPES[$s['type']]['package'] ?? false; ?>
          <tr>
            <td data-label="ID" class="mono"><?= (int) $s['id'] ?></td>
            <td class="cell-main"><span class="cell-title"><?= e($s['name']) ?></span><?php if ($s['average_time']): ?><div class="cell-sub"><?= icon('clock') ?> <?= e($s['average_time']) ?></div><?php endif ?></td>
            <td data-label="Rate" class="num fw-bold nowrap"><?= e(rate($s['rate'])) ?><span class="text-muted text-xs"><?= $pkg ? ' / pkg' : ' / 1K' ?></span></td>
            <td data-label="Min / Max" class="num nowrap"><?= $pkg ? '—' : number_format((int) $s['min_quantity']) . ' / ' . number_format((int) $s['max_quantity']) ?></td>
            <td data-label="Features"><span class="svc-flags"><?php if ($s['refill']): ?><span class="badge badge-success no-dot">Refill</span><?php endif ?><?php if ($s['cancel']): ?><span class="badge badge-info no-dot">Cancel</span><?php endif ?><?php if ($s['dripfeed']): ?><span class="badge badge-purple no-dot">Drip-feed</span><?php endif ?><?php if ($s['type'] === 'subscription' || !empty($s['subscription_enabled'])): ?><span class="badge badge-info no-dot"><?= $s['type'] === 'subscription' ? 'Subscription' : 'Auto-repeat' ?></span><?php endif ?></span></td>
            <td class="actions"><a class="btn btn-soft btn-sm" href="<?= e(url('/order?service=' . (int) $s['id'])) ?>">Order</a></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach ?>
</div>
