<?php $this->extend('install/layout'); $step = 1; \App\Core\View::share('step', 1); ?>
<div class="card">
  <div class="card-header"><h2>Server requirements</h2><?= $ok ? '<span class="badge badge-success">Ready</span>' : '<span class="badge badge-danger">Action needed</span>' ?></div>
  <ul class="list-plain list-rows">
    <?php foreach ($checks as [$label, $pass, $note, $required]): ?>
    <li><div><div class="cell-title"><?= e($label) ?></div><?php if ($note): ?><div class="cell-sub"><?= e($note) ?></div><?php endif ?></div>
      <?= $pass ? '<span class="badge badge-success">OK</span>' : ($required ? '<span class="badge badge-danger">Missing</span>' : '<span class="badge badge-warning">Optional</span>') ?></li>
    <?php endforeach ?>
  </ul>
  <div class="card-footer flex justify-between items-center wrap gap-1">
    <span class="text-sm text-muted">Fix required items in cPanel → Select PHP Version / File Manager, then reload.</span>
    <?php if ($ok): ?><a class="btn btn-primary" href="<?= e(url('/install/database')) ?>">Continue <?= icon('arrow-right') ?></a><?php else: ?><a class="btn btn-secondary" href="<?= e(url('/install')) ?>">Re-check</a><?php endif ?>
  </div>
</div>
