<?php $this->extend('layouts/admin'); use App\Services\SubscriptionService;
$base = admin_url('subscriptions/' . $sub['id']);
$open = in_array($sub['status'], ['active', 'paused', 'suspended'], true);
$sm = SubscriptionService::summary($sub); $posts = $sm['posts'];
$postOrder = $posts && $orders ? $orders[0] : null; ?>
<div class="page-head">
  <div><ol class="breadcrumb"><li><a href="<?= e(admin_url('subscriptions')) ?>">Subscriptions</a></li><li>#<?= (int) $sub['id'] ?></li></ol>
    <h1>Subscription #<?= (int) $sub['id'] ?> <?= status_badge($sub['status']) ?></h1>
    <p>by <a href="<?= e(admin_url('users/' . $sub['user_id'])) ?>"><?= e($sub['username']) ?></a> · <?= e(fmt_date($sub['created_at'])) ?></p></div>
  <?php if (can('orders.manage') && $sub['status'] === 'active' && !$posts): ?><form method="post" action="<?= e($base . '/action') ?>" data-confirm="Place the next delivery now? The user is charged as usual."><?= csrf_field() ?><input type="hidden" name="action" value="run"><button class="btn btn-secondary" type="submit"><?= icon('zap') ?> Run next delivery now</button></form><?php endif ?>
</div>

<?php if ($sub['last_error'] && in_array($sub['status'], ['active', 'suspended'], true)): ?>
<div class="alert alert-<?= $sub['status'] === 'suspended' ? 'danger' : 'warning' ?>"><?= icon('alert') ?><div><div class="alert-title"><?= $sub['status'] === 'suspended' ? 'Suspended after ' . (int) $sub['attempts'] . ' failed attempts' : 'Retrying (attempt ' . (int) $sub['attempts'] . ' of ' . count(SubscriptionService::RETRY_MINUTES) . ')' ?></div><?= e($sub['last_error']) ?></div></div>
<?php endif ?>

<div class="grid-main">
  <div>
    <div class="card mb-2"><div class="card-body"><dl class="dl">
      <dt>Service</dt><dd><?= e($sub['service']) ?> <span class="text-muted">(#<?= (int) $sub['service_id'] ?><?= $sub['service_status'] !== 'active' ? ', ' . e($sub['service_status']) : '' ?>)</span></dd>
      <dt>Type</dt><dd><?= e($sm['kind']) ?></dd>
      <dt><?= e($sm['target_label']) ?></dt><dd class="break"><?= $posts ? '<span class="mono break-words">' . e($sub['link']) . '</span>' : link_html($sub['link']) ?></dd>
      <dt>Quantity</dt><dd><?= e($sm['qty']) ?></dd>
      <?php if ($posts): ?>
      <dt>Posts</dt><dd><?= e($sm['schedule']) ?> · <?= $sm['done'] ?> of <?= $sm['total'] ?> processed</dd>
      <dt>Expiry</dt><dd><?= e($sm['expiry']) ?></dd>
      <dt>Provider status</dt><dd><?= e($sub['provider_status'] ?? '—') ?><?= $postOrder && $postOrder['provider_order_id'] ? ' · provider order <span class="mono">' . e($postOrder['provider_order_id']) . '</span>' : '' ?></dd>
      <dt>Money</dt><dd>Rate <?= e(rate($sub['rate'])) ?> · reserved <?= e(money($sub['prepaid'])) ?><?= $sub['final_charge'] !== null ? ' · final ' . e(money($sub['final_charge'])) . ' · refunded ' . e(money($sub['refunded'])) : ' · not settled yet' ?> · balance <?= e(money($user['balance'])) ?></dd>
      <?php else: ?>
      <dt>Schedule</dt><dd><?= e($sm['schedule']) ?> · <?= $sm['done'] ?> of <?= $sm['total'] ?> deliveries</dd>
      <dt>Next run</dt><dd><?= $sub['next_run_at'] && $sub['status'] === 'active' ? e(fmt_date($sub['next_run_at'], 'M j, Y H:i:s')) : '—' ?><?= $sub['locked_until'] && strtotime($sub['locked_until'] . ' UTC') > time() ? ' <span class="badge badge-info">processing</span>' : '' ?></dd>
      <dt>Per delivery</dt><dd><?= e(money($perDelivery)) ?> at the user's current price · spent <?= e(money($spent)) ?> · balance <?= e(money($user['balance'])) ?></dd>
      <?php endif ?>
    </dl></div></div>
    <div class="card mb-2"><div class="card-header"><h2>Deliveries (orders)</h2></div><div class="table-wrap"><table class="table table-cards">
      <thead><tr><th>Delivery</th><th>Order</th><th class="num">Charge</th><th>Status</th><th>Placed</th></tr></thead><tbody>
      <?php foreach ($orders as $o): ?><tr><td data-label="Delivery" class="mono">#<?= (int) $o['subscription_cycle'] ?></td><td data-label="Order"><a class="mono" href="<?= e(admin_url('orders/' . $o['id'])) ?>">#<?= (int) $o['id'] ?></a><?= $o['provider_order_id'] ? ' <span class="cell-sub mono">' . e($o['provider_order_id']) . '</span>' : '' ?></td><td data-label="Charge" class="num nowrap"><?= e(money($o['charge'])) ?></td><td data-label="Status"><?= status_badge($o['status']) ?></td><td data-label="Placed" class="text-sm nowrap"><?= e(fmt_date($o['created_at'])) ?></td></tr><?php endforeach ?>
      <?php if (!$orders): ?><tr><td colspan="5" class="text-muted text-center">No deliveries yet.</td></tr><?php endif ?>
    </tbody></table></div></div>
    <div class="card"><div class="card-header"><h2>History</h2></div><div class="card-body"><ul class="timeline">
      <?php foreach ($logs as $l): ?><li><div><strong><?= e(ucfirst(str_replace('_', ' ', $l['event']))) ?></strong><?= $l['cycle'] ? ' · delivery ' . (int) $l['cycle'] : '' ?><?= $l['order_id'] ? ' · <a href="' . e(admin_url('orders/' . $l['order_id'])) . '">order #' . (int) $l['order_id'] . '</a>' : '' ?> <span class="text-muted text-xs">by <?= e($l['actor']) ?></span></div><?php if ($l['message']): ?><div class="text-sm break"><?= e($l['message']) ?></div><?php endif ?><div class="text-xs text-muted"><?= e(fmt_date($l['created_at'], 'M j, Y H:i:s')) ?></div></li><?php endforeach ?>
    </ul></div></div>
  </div>
  <div>
    <?php if (can('orders.manage') && $posts && $sub['status'] === 'active'): ?>
    <div class="card mb-2"><div class="card-header"><h2>Manage</h2></div><div class="card-body">
      <?php if (!$postOrder || !$postOrder['provider_order_id']): ?>
      <form method="post" action="<?= e($base . '/action') ?>" class="mb-2"><?= csrf_field() ?><input type="hidden" name="action" value="record">
        <p class="hint mt-0">Manual service: record how many posts were delivered. <strong>Finish</strong> settles the subscription and refunds the unused reserve.</p>
        <div class="field"><label for="posts-done">Posts processed</label><input class="input" id="posts-done" name="posts_done" type="number" min="0" max="<?= $sm['total'] ?>" value="<?= $sm['done'] ?>" required></div>
        <div class="field"><?= \App\Helpers\Form::check('finish', 'Finish and settle now', false) ?></div>
        <input type="hidden" name="reason" value="">
        <button class="btn btn-secondary btn-block" type="submit">Save</button>
      </form>
      <?php endif ?>
      <form method="post" action="<?= e($base . '/action') ?>"><?= csrf_field() ?>
        <div class="field"><label for="sub-action">Cancel</label><select class="select" id="sub-action" name="action">
          <option value="cancel">Cancel (asks the provider; settles when it confirms)</option>
          <option value="force_cancel">Force cancel now (settle with posts processed so far)</option>
        </select></div>
        <div class="field"><label for="sub-reason">Reason</label><input class="input" id="sub-reason" name="reason" required maxlength="300" placeholder="Kept in history and audit log"></div>
        <button class="btn btn-danger btn-block" type="submit" data-confirm-text>Cancel subscription</button>
        <p class="hint">Force cancel refunds the unused reserve immediately; use it only when the provider has stopped delivering.</p>
      </form>
    </div></div>
    <?php endif ?>
    <?php if (can('orders.manage') && $open && !$posts): ?>
    <div class="card"><div class="card-header"><h2>Manage</h2></div><div class="card-body">
      <form method="post" action="<?= e($base . '/action') ?>"><?= csrf_field() ?>
        <div class="field"><label for="sub-action">Action</label><select class="select" id="sub-action" name="action">
          <?php if ($sub['status'] === 'active'): ?><option value="pause">Pause</option><?php endif ?>
          <?php if (in_array($sub['status'], ['paused', 'suspended'], true)): ?><option value="resume">Resume</option><?php endif ?>
          <option value="cancel">Cancel (stops future deliveries)</option>
        </select></div>
        <div class="field"><label for="sub-reason">Reason</label><input class="input" id="sub-reason" name="reason" required maxlength="300" placeholder="Kept in history and audit log"></div>
        <button class="btn btn-primary btn-block" type="submit">Apply</button>
        <p class="hint">Deliveries already ordered are not changed or refunded here; manage them on each order.</p>
      </form>
    </div></div>
    <?php endif ?>
  </div>
</div>
