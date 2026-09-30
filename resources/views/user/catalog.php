<?php $this->extend('layouts/user'); use App\Core\Money; ?>
<div class="page-head">
  <div><h1>Services</h1><p>Prices shown include your personal discount<?= Money::isPositive($discount) ? ' (' . e(rtrim(rtrim($discount, '0'), '.')) . '% off)' : '' ?>.</p></div>
</div>
<form class="toolbar" method="get" action="<?= e(url('/catalog')) ?>">
  <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or ID">
  <select class="select" name="category" data-autosubmit>
    <option value="">All categories</option>
    <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $cat === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['n']) ?></option><?php endforeach ?>
  </select>
  <button class="btn btn-primary" type="submit"><?= icon('search') ?> Filter</button>
</form>
<?php if (!$grouped): ?><div class="card"><div class="empty"><?= icon('search') ?><h3>No services match</h3></div></div><?php endif ?>
<?php foreach ($grouped as $g): ?>
<div class="card mb-2">
  <div class="card-header"><h2 class="heading-icon"><?= \App\Helpers\Platforms::icon($g['platform']) ?> <span><?= e($g['name']) ?></span></h2></div>
  <div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>ID</th><th>Service</th><th class="num">Rate</th><th class="num">Min / Max</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($g['services'] as $s): ?>
      <tr>
        <td data-label="ID" class="mono"><?= $s['id'] ?></td>
        <td class="cell-main"><span class="cell-title"><?= e($s['n']) ?></span>
          <div class="svc-flags mt-1"><?php if ($s['rf']): ?><span class="badge badge-success no-dot">Refill</span><?php endif ?><?php if ($s['cn']): ?><span class="badge badge-info no-dot">Cancel</span><?php endif ?><?php if ($s['df']): ?><span class="badge badge-purple no-dot">Drip-feed</span><?php endif ?><?php if ($s['t']): ?><span class="text-xs text-muted"><?= icon('clock') ?> <?= e($s['t']) ?></span><?php endif ?></div></td>
        <td data-label="Rate" class="num fw-bold nowrap"><?= e(rate($s['r'])) ?><span class="text-muted text-xs"><?= $s['pk'] ? ' / pkg' : ' / 1K' ?></span></td>
        <td data-label="Min / Max" class="num nowrap"><?= $s['pk'] ? '—' : number_format($s['mi']) . ' / ' . number_format($s['ma']) ?></td>
        <td class="actions"><a class="btn btn-soft btn-sm" href="<?= e(url('/order?service=' . $s['id'])) ?>">Order</a></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
</div>
<?php endforeach ?>
