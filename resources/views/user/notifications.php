<?php $this->extend('layouts/user'); ?>
<div class="page-head"><div><h1>Notifications</h1></div>
  <form method="post" action="<?= e(url('/notifications/read')) ?>"><?= csrf_field() ?><button class="btn btn-secondary" type="submit"><?= icon('check') ?> Mark all as read</button></form></div>
<div class="card">
  <?php if (!$items->items): ?><div class="empty"><?= icon('bell') ?><h3>You're all caught up</h3></div><?php else: ?>
  <ul class="list-plain list-rows">
    <?php foreach ($items->items as $n): $ic = ['order' => 'list', 'payment' => 'wallet', 'ticket' => 'chat'][$n['type']] ?? 'bell'; ?>
    <li style="<?= $n['read_at'] ? '' : 'background:var(--primary-50)' ?>">
      <div class="flex gap-2" style="min-width:0">
        <span class="stat-icon" style="width:36px;height:36px"><?= icon($ic) ?></span>
        <div style="min-width:0"><div class="cell-title"><?php if ($n['url'] && str_starts_with($n['url'], '/')): ?><a href="<?= e(url($n['url'])) ?>"><?= e($n['title']) ?></a><?php else: ?><?= e($n['title']) ?><?php endif ?></div>
          <?php if ($n['body']): ?><div class="cell-sub"><?= e($n['body']) ?></div><?php endif ?></div>
      </div>
      <span class="text-xs text-muted nowrap"><?= e(time_ago($n['created_at'])) ?></span>
    </li>
    <?php endforeach ?>
  </ul>
  <?= $items->links(\App\Core\App::request()) ?>
  <?php endif ?>
</div>
