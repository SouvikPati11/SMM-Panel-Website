<?php $this->extend('layouts/user');
use App\Services\SubscriptionService;
$base = url('/subscriptions/' . $sub['id']);
$sm = SubscriptionService::summary($sub);
$pct = (int) round(100 * $sm['done'] / max(1, $sm['total']));
$posts = $sm['posts']; ?>
<div class="page-head">
  <div><ol class="breadcrumb"><li><a href="<?= e(url('/subscriptions')) ?>">Subscriptions</a></li><li>#<?= (int) $sub['id'] ?></li></ol>
    <h1>Subscription #<?= (int) $sub['id'] ?> <?= status_badge($sub['status']) ?></h1>
    <p><?= e($sub['service']) ?></p></div>
  <div class="btn-group">
    <?php if (!$posts && $sub['status'] === 'active'): ?><form method="post" action="<?= e($base . '/action') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pause"><button class="btn btn-secondary" type="submit"><?= icon('clock') ?> Pause</button></form><?php endif ?>
    <?php if (!$posts && in_array($sub['status'], ['paused', 'suspended'], true)): ?><form method="post" action="<?= e($base . '/action') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="resume"><button class="btn btn-primary" type="submit"><?= icon('refresh') ?> Resume</button></form><?php endif ?>
    <?php if ((!$posts && in_array($sub['status'], ['active', 'paused', 'suspended'], true)) || ($posts && $sub['status'] === 'active')): ?><form method="post" action="<?= e($base . '/action') ?>" data-confirm="<?= $posts ? 'Cancel this subscription? Posts already processed are charged; the rest of the reserve is refunded when the provider confirms.' : 'Cancel this subscription? Deliveries already ordered are not affected.' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit"><?= icon('x') ?> Cancel</button></form><?php endif ?>
  </div>
</div>

<?php if (!$posts && ($sub['status'] === 'suspended' || ($sub['last_error'] && $sub['status'] === 'active'))): ?>
<div class="alert alert-<?= $sub['status'] === 'suspended' ? 'danger' : 'warning' ?>"><?= icon('alert') ?><div><div class="alert-title"><?= $sub['status'] === 'suspended' ? 'Paused after repeated failures' : 'The last delivery attempt failed — retrying automatically' ?></div><?= e($sub['last_error']) ?><?= $sub['status'] === 'suspended' ? ' Fix the cause (e.g. add funds), then press Resume.' : '' ?></div></div>
<?php endif ?>
<?php if ($posts && $sub['status'] === 'cancelled' && $sub['final_charge'] === null): ?>
<div class="alert alert-info"><?= icon('info') ?><div>Cancellation accepted. The unused part of your reserve is refunded as soon as the provider confirms the final number of processed posts.</div></div>
<?php endif ?>

<div class="stats stats-3 mb-2">
  <div class="stat"><div><div class="stat-label"><?= $posts ? 'Posts processed' : 'Deliveries' ?></div><div class="stat-value"><?= $sm['done'] ?> / <?= $sm['total'] ?></div><div class="progress mt-1" aria-hidden="true"><span style="width:<?= $pct ?>%"></span></div></div></div>
  <?php if ($posts): ?>
  <div class="stat"><div><div class="stat-label">Expiry</div><div class="stat-value text-base"><?= e($sm['expiry']) ?></div><div class="stat-meta"><?= e(SubscriptionService::DELAYS[(int) $sub['delay_minutes']] ?? '') ?> delay</div></div></div>
  <div class="stat"><div><div class="stat-label"><?= $sub['final_charge'] !== null ? 'Final charge' : 'Reserved' ?></div><div class="stat-value"><?= e(money($sub['final_charge'] ?? $sub['prepaid'])) ?></div><div class="stat-meta"><?= $sub['final_charge'] !== null ? 'Refunded ' . e(money($sub['refunded'])) . ' of ' . e(money($sub['prepaid'])) : 'Unused part refunded when it ends' ?></div></div></div>
  <?php else: ?>
  <div class="stat"><div><div class="stat-label">Next delivery</div><div class="stat-value text-base"><?= $sub['status'] === 'active' && $sub['next_run_at'] ? e(fmt_date($sub['next_run_at'])) : '—' ?></div><div class="stat-meta"><?= e($sm['schedule']) ?></div></div></div>
  <div class="stat"><div><div class="stat-label">Per delivery (current price)</div><div class="stat-value"><?= e(money($perDelivery)) ?></div><div class="stat-meta">Spent so far <?= e(money($spent)) ?></div></div></div>
  <?php endif ?>
</div>

<div class="grid-main">
  <div>
    <div class="card mb-2"><div class="card-header"><h2><?= $posts ? 'Order' : 'Deliveries' ?></h2></div><div class="table-wrap"><table class="table table-cards">
      <thead><tr><th><?= $posts ? 'Type' : 'Delivery' ?></th><th>Order</th><th class="num">Charge</th><th>Status</th><th>Placed</th></tr></thead><tbody>
      <?php foreach ($orders as $o): ?><tr><td data-label="<?= $posts ? 'Type' : 'Delivery' ?>" class="mono"><?= $posts ? 'Subscription' : '#' . (int) $o['subscription_cycle'] ?></td><td data-label="Order"><a class="mono" href="<?= e(url('/orders/' . $o['id'])) ?>">#<?= (int) $o['id'] ?></a></td><td data-label="Charge" class="num nowrap"><?= e(money($o['charge'])) ?><?= \App\Core\Money::isPositive((string) $o['refunded_amount']) ? '<div class="cell-sub">refunded ' . e(money($o['refunded_amount'])) . '</div>' : '' ?></td><td data-label="Status"><?= status_badge($o['status']) ?></td><td data-label="Placed" class="text-sm nowrap"><?= e(fmt_date($o['created_at'])) ?></td></tr><?php endforeach ?>
      <?php if (!$orders): ?><tr><td colspan="5" class="text-muted text-center">No deliveries yet.</td></tr><?php endif ?>
    </tbody></table></div></div>
  </div>
  <div>
    <div class="card mb-2"><div class="card-body"><dl class="dl">
      <dt><?= e($sm['target_label']) ?></dt><dd class="break"><?= $posts ? '<span class="mono break-words">' . e($sub['link']) . '</span>' : link_html($sub['link']) ?></dd>
      <dt>Quantity</dt><dd><?= e($sm['qty']) ?></dd>
      <?php if ($posts): ?>
      <dt>Posts</dt><dd><?= (int) $sub['total_cycles'] ?> new<?= (int) $sub['old_posts'] ? ' + ' . (int) $sub['old_posts'] . ' old' : '' ?></dd>
      <dt>Delay</dt><dd><?= e(SubscriptionService::DELAYS[(int) $sub['delay_minutes']] ?? ((int) $sub['delay_minutes'] . ' min')) ?></dd>
      <dt>Rate</dt><dd><?= e(rate($sub['rate'])) ?> per 1000</dd>
      <?php endif ?>
      <dt>Created</dt><dd><?= e(fmt_date($sub['created_at'])) ?></dd>
      <?php if ($sub['completed_at']): ?><dt><?= $sub['status'] === 'expired' ? 'Expired' : 'Completed' ?></dt><dd><?= e(fmt_date($sub['completed_at'])) ?></dd><?php endif ?>
      <?php if ($sub['cancelled_at']): ?><dt>Cancelled</dt><dd><?= e(fmt_date($sub['cancelled_at'])) ?></dd><?php endif ?>
    </dl></div></div>
    <div class="card"><div class="card-header"><h2>History</h2></div><div class="card-body"><ul class="timeline">
      <?php foreach ($logs as $l): ?><li><div><strong><?= e(ucfirst(str_replace('_', ' ', $l['event']))) ?></strong><?= $l['cycle'] && !$posts ? ' · delivery ' . (int) $l['cycle'] : '' ?></div><?php if ($l['message']): ?><div class="text-sm break"><?= e($l['message']) ?></div><?php endif ?><div class="text-xs text-muted"><?= e(fmt_date($l['created_at'], 'M j, Y H:i')) ?></div></li><?php endforeach ?>
    </ul></div></div>
  </div>
</div>
