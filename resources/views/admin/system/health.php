<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>System health</h1></div><a class="btn btn-secondary" href="<?= e(admin_url('cron')) ?>"><?= icon('clock') ?> Cron tasks</a></div>
<div class="grid-2">
  <div class="card"><div class="card-header"><h2>Checks</h2></div><ul class="list-plain list-rows"><?php foreach ($checks as [$label, $ok, $note, $req]): ?>
    <li><div><div class="cell-title"><?= e($label) ?></div><?php if ($note && !$ok): ?><div class="cell-sub"><?= e($note) ?></div><?php endif ?></div><?= $ok ? '<span class="badge badge-success">OK</span>' : ($req ? '<span class="badge badge-danger">Fix</span>' : '<span class="badge badge-warning">Advice</span>') ?></li>
  <?php endforeach ?></ul><?php if ($pendingMigrations): ?><div class="card-body"><form method="post" action="<?= e(admin_url('health/migrate')) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Apply database upgrades</button></form></div><?php endif ?></div>
  <div>
    <div class="card mb-2"><div class="card-header"><h2>Environment</h2></div><div class="card-body"><dl class="dl"><?php foreach ($info as $k => $v): ?><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd><?php endforeach ?></dl></div></div>
    <div class="card mb-2"><div class="card-header"><h2>Queues & errors</h2></div><ul class="list-plain list-rows"><?php foreach ($counts as $k => $v): ?><li><span><?= e($k) ?></span><strong class="<?= $v > 0 && preg_match('/rror|failed|review/', $k) ? 'text-danger' : '' ?>"><?= number_format($v) ?></strong></li><?php endforeach ?></ul></div>
    <div class="card"><div class="card-header"><h2>Cron</h2></div><ul class="list-plain list-rows"><?php foreach ($cron as $t => $c): ?><li><span class="mono"><?= e($t) ?></span><span><?= $c['last'] ? e(time_ago($c['last']['started_at'])) : 'never' ?> <?= $c['stale'] ? '<span class="badge badge-danger">stale</span>' : status_badge($c['last']['status']) ?></span></li><?php endforeach ?></ul></div>
  </div>
</div>
