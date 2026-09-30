<?php $this->extend('layouts/admin'); $req = \App\Core\App::request(); ?>
<div class="page-head"><div><h1>Subscriptions</h1><p>Auto-repeating orders. Each delivery is a normal order placed by the <code>subscriptions</code> cron task.</p></div></div>
<div class="chips">
  <a class="chip <?= !$f['status'] && !$f['failing'] ? 'active' : '' ?>" href="<?= e(admin_url('subscriptions')) ?>">All <span class="count"><?= number_format(array_sum($counts)) ?></span></a>
  <?php if ($failing): ?><a class="chip <?= $f['failing'] ? 'active' : '' ?>" href="<?= e(admin_url('subscriptions?failing=1')) ?>" style="border-color:var(--warning)"><?= icon('alert') ?> Failing <span class="count"><?= $failing ?></span></a><?php endif ?>
  <?php foreach (\App\Services\SubscriptionService::STATUSES as $s): if (empty($counts[$s])) { continue; } ?>
    <a class="chip <?= $f['status'] === $s ? 'active' : '' ?>" href="<?= e(admin_url('subscriptions?status=' . $s)) ?>"><?= e(ucfirst($s)) ?> <span class="count"><?= number_format((int) $counts[$s]) ?></span></a>
  <?php endforeach ?>
</div>
<form class="toolbar" method="get" action="<?= e(admin_url('subscriptions')) ?>">
  <?php if ($f['status']): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif ?>
  <input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Subscription ID, username or link">
  <button class="btn btn-secondary" type="submit"><?= icon('search') ?> Search</button>
</form>
<div class="card">
  <?php if (!$subs->items): ?><div class="empty"><?= icon('clock') ?><h3>No subscriptions</h3><p>Enable "Allow auto-subscriptions" on a service to let users subscribe.</p></div><?php else: ?>
  <div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>ID</th><th>User</th><th>Service</th><th class="num">Quantity</th><th>Progress</th><th>Next run / expiry</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($subs->items as $s): $sm = \App\Services\SubscriptionService::summary($s); ?>
      <tr>
        <td data-label="ID"><a class="mono" href="<?= e(admin_url('subscriptions/' . $s['id'])) ?>">#<?= (int) $s['id'] ?></a></td>
        <td data-label="User"><a href="<?= e(admin_url('users/' . $s['user_id'])) ?>"><?= e($s['username']) ?></a></td>
        <td class="cell-main"><span class="cell-title"><?= e(str_limit($s['service'], 60)) ?></span><div class="cell-sub"><?= e($sm['kind'] . ' · ' . $sm['schedule']) ?></div><?php if ($sm['posts']): ?><div class="cell-sub mono break-words"><?= e($s['link']) ?></div><?php endif ?><?php if ($s['last_error'] && in_array($s['status'], ['active', 'suspended'], true)): ?><div class="cell-sub text-danger break"><?= e(str_limit($s['last_error'], 120)) ?> (attempt <?= (int) $s['attempts'] ?>)</div><?php endif ?></td>
        <td data-label="Quantity" class="num nowrap"><?= e($sm['qty']) ?></td>
        <td data-label="Progress"><div class="progress-line"><span class="progress" aria-hidden="true"><span style="width:<?= (int) round(100 * $sm['done'] / max(1, $sm['total'])) ?>%"></span></span><span class="text-sm nowrap"><?= $sm['done'] ?> / <?= $sm['total'] ?></span></div></td>
        <td data-label="<?= $sm['posts'] ? 'Expiry' : 'Next run' ?>" class="text-sm nowrap"><?= $sm['posts'] ? e($sm['expiry']) : ($s['status'] === 'active' && $s['next_run_at'] ? e(fmt_date($s['next_run_at'])) : '—') ?></td>
        <td data-label="Status"><?= status_badge($s['status']) ?></td>
      </tr>
    <?php endforeach ?></tbody></table></div>
  <div class="flex justify-between items-center wrap" style="padding:0 16px"><span class="text-sm text-muted"><?= e($subs->summary()) ?></span><?= $subs->links($req) ?></div>
  <?php endif ?>
</div>
