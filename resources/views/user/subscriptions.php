<?php $this->extend('layouts/user');
$tabs = ['' => 'All', 'active' => 'Active', 'paused' => 'Paused', 'suspended' => 'Needs attention', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$total = array_sum($counts); ?>
<div class="page-head">
  <div><h1>Subscriptions</h1><p>Orders that repeat automatically. Each delivery is a normal order, charged from your balance when it is placed.</p></div>
  <a class="btn btn-primary" href="<?= e(url('/order')) ?>"><?= icon('plus') ?> New subscription</a>
</div>
<div class="chips">
  <?php foreach ($tabs as $k => $label): $n = $k === '' ? $total : (int) ($counts[$k] ?? 0); if ($k !== '' && !$n && $status !== $k) { continue; } ?>
    <a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(url('/subscriptions' . ($k ? '?status=' . $k : ''))) ?>"><?= e($label) ?> <span class="count"><?= number_format($n) ?></span></a>
  <?php endforeach ?>
</div>
<div class="card">
  <?php if (!$subs->items): ?>
    <div class="empty"><?= icon('refresh') ?><h3>No subscriptions yet</h3><p>On the New order page, pick a service marked "Subscription" and choose <strong>Auto-subscription</strong>.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>ID</th><th>Service</th><th>Link / username</th><th class="num">Quantity</th><th>Progress</th><th>Next delivery / expiry</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($subs->items as $s): $sm = \App\Services\SubscriptionService::summary($s); ?>
      <tr>
        <td data-label="ID"><a class="mono" href="<?= e(url('/subscriptions/' . $s['id'])) ?>">#<?= (int) $s['id'] ?></a></td>
        <td class="cell-main"><span class="cell-title"><?= e(str_limit($s['service'], 70)) ?></span><div class="cell-sub"><?= e($sm['schedule']) ?></div></td>
        <td data-label="<?= e($sm['target_label']) ?>" class="link-cell"><?= $sm['posts'] ? '<span class="mono break-words">' . e($s['link']) . '</span>' : link_html($s['link']) ?></td>
        <td data-label="Quantity" class="num nowrap"><?= e($sm['qty']) ?></td>
        <td data-label="Progress"><div class="progress-line"><span class="progress" aria-hidden="true"><span style="width:<?= (int) round(100 * $sm['done'] / max(1, $sm['total'])) ?>%"></span></span><span class="text-sm nowrap"><?= $sm['done'] ?> / <?= $sm['total'] ?></span></div></td>
        <td data-label="<?= $sm['posts'] ? 'Expiry' : 'Next delivery' ?>" class="text-sm nowrap"><?= $sm['posts'] ? e($sm['expiry']) : ($s['status'] === 'active' && $s['next_run_at'] ? e(fmt_date($s['next_run_at'])) : '—') ?></td>
        <td data-label="Status"><?= status_badge($s['status']) ?></td>
        <td class="actions"><a class="btn btn-ghost btn-sm" href="<?= e(url('/subscriptions/' . $s['id'])) ?>">Details</a></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <div class="flex justify-between items-center wrap" style="padding:0 16px"><span class="text-sm text-muted"><?= e($subs->summary()) ?></span><?= $subs->links(\App\Core\App::request()) ?></div>
  <?php endif ?>
</div>
