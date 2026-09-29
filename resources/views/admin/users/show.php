<?php $this->extend('layouts/admin'); use App\Helpers\Form; use App\Core\Money; $base = admin_url('users/' . $user['id']); ?>
<div class="page-head">
  <div><ol class="breadcrumb"><li><a href="<?= e(admin_url('users')) ?>">Users</a></li><li>#<?= (int) $user['id'] ?></li></ol>
    <h1><?= e($user['username']) ?> <?= status_badge($user['status']) ?></h1>
    <p><?= e($user['email']) ?> · joined <?= e(fmt_date($user['created_at'], 'M j, Y')) ?> from <?= e($user['register_ip'] ?: '—') ?><?= $user['referrer'] ? ' · referred by ' . e($user['referrer']) : '' ?></p></div>
</div>
<div class="stats">
  <div class="stat balance-card"><div><div class="stat-label">Balance</div><div class="stat-value"><?= e(money($user['balance'], 4)) ?></div><div class="stat-meta">Referral wallet <?= e(money($user['referral_balance'])) ?></div></div></div>
  <div class="stat"><div><div class="stat-label">Deposits / spent</div><div class="stat-value"><?= e(money($user['total_deposits'])) ?></div><div class="stat-meta"><?= e(money($user['total_spent'])) ?> spent</div></div></div>
  <div class="stat"><div><div class="stat-label">Orders</div><div class="stat-value"><?= number_format($orderStats['total']) ?></div><div class="stat-meta"><?= $orderStats['completed'] ?> completed · <?= $orderStats['pending'] + $orderStats['processing'] ?> active</div></div></div>
  <div class="stat"><div><div class="stat-label">Security</div><div class="stat-value" style="font-size:1rem"><?= (int) $user['twofa_enabled'] ? '2FA on' : '2FA off' ?> · API <?= $apiKey ? 'key active' : 'no key' ?></div><div class="stat-meta">Last login <?= e(time_ago($user['last_login_at'])) ?> <?= e($user['last_login_ip'] ?? '') ?> · <?= $referrals ?> referrals</div></div></div>
</div>

<div class="grid-main">
  <div>
    <div class="tabs"><?php foreach (['orders' => 'Orders', 'transactions' => 'Transactions', 'payments' => 'Payments', 'tickets' => 'Tickets', 'activity' => 'Login activity'] as $k => $l): ?><a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= e($base . '?tab=' . $k) ?>"><?= e($l) ?></a><?php endforeach ?></div>
    <div class="card"><div class="table-wrap"><table class="table table-cards">
      <?php if ($tab === 'orders'): ?>
        <thead><tr><th>ID</th><th>Service</th><th class="num">Charge</th><th>Status</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($list->items as $o): ?><tr><td data-label="ID"><a class="mono" href="<?= e(admin_url('orders/' . $o['id'])) ?>">#<?= (int) $o['id'] ?></a></td><td class="cell-main"><?= e(str_limit($o['service'], 60)) ?><div class="cell-sub truncate" style="max-width:300px"><?= e($o['link']) ?></div></td><td data-label="Charge" class="num"><?= e(money($o['charge'])) ?></td><td data-label="Status"><?= status_badge($o['status']) ?></td><td data-label="Date" class="text-sm nowrap"><?= e(fmt_date($o['created_at'])) ?></td></tr><?php endforeach ?>
      <?php elseif ($tab === 'transactions'): ?>
        <thead><tr><th>ID</th><th>Type</th><th>Description</th><th class="num">Amount</th><th class="num">After</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($list->items as $t): ?><tr><td data-label="ID" class="mono">#<?= (int) $t['id'] ?></td><td data-label="Type"><?= e(str_replace('_', ' ', $t['type'])) ?><?= $t['wallet'] === 'referral' ? ' (ref)' : '' ?></td><td class="cell-main"><?= e($t['description']) ?><?= $t['admin_id'] ? ' <span class="badge badge-purple no-dot">admin #' . (int) $t['admin_id'] . '</span>' : '' ?></td><td data-label="Amount" class="num fw-bold <?= Money::isNegative((string) $t['amount']) ? '' : 'text-success' ?>"><?= e(money($t['amount'], 4)) ?></td><td data-label="After" class="num"><?= e(money($t['balance_after'], 4)) ?></td><td data-label="Date" class="text-sm nowrap"><?= e(fmt_date($t['created_at'])) ?></td></tr><?php endforeach ?>
      <?php elseif ($tab === 'payments'): ?>
        <thead><tr><th>ID</th><th>Gateway</th><th class="num">Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($list->items as $p): ?><tr><td data-label="ID" class="mono">#<?= (int) $p['id'] ?></td><td data-label="Gateway"><?= e(\App\Services\PaymentService::gatewayLabel($p['gateway'])) ?></td><td data-label="Amount" class="num"><?= e(money($p['amount'])) ?></td><td data-label="Status"><?= status_badge($p['status']) ?></td><td data-label="Date" class="text-sm"><?= e(fmt_date($p['created_at'])) ?></td></tr><?php endforeach ?>
      <?php elseif ($tab === 'tickets'): ?>
        <thead><tr><th>ID</th><th>Subject</th><th>Status</th><th>Updated</th></tr></thead><tbody>
        <?php foreach ($list->items as $t): ?><tr><td data-label="ID" class="mono">#<?= (int) $t['id'] ?></td><td class="cell-main"><a href="<?= e(admin_url('tickets/' . $t['id'])) ?>"><?= e($t['subject']) ?></a></td><td data-label="Status"><?= status_badge($t['status']) ?></td><td data-label="Updated" class="text-sm"><?= e(time_ago($t['last_reply_at'])) ?></td></tr><?php endforeach ?>
      <?php else: ?>
        <thead><tr><th>Date</th><th>IP</th><th>Browser</th><th>Result</th></tr></thead><tbody>
        <?php foreach ($list->items as $l): ?><tr><td data-label="Date" class="text-sm nowrap"><?= e(fmt_date($l['created_at'])) ?></td><td data-label="IP" class="mono"><?= e($l['ip']) ?></td><td class="cell-main text-sm"><?= e(str_limit($l['user_agent'], 70)) ?></td><td data-label="Result"><?= (int) $l['success'] ? status_badge('success') : status_badge('failed') ?></td></tr><?php endforeach ?>
      <?php endif ?>
      <?php if (!$list->items): ?><tr><td colspan="6" class="text-center text-muted" style="padding:28px">Nothing here yet.</td></tr><?php endif ?>
      </tbody></table></div><?= $list->links(\App\Core\App::request()) ?></div>
  </div>

  <div>
    <?php if (can('users.balance')): ?>
    <div class="card mb-2"><div class="card-header"><h2>Adjust balance</h2></div><div class="card-body">
      <form method="post" action="<?= e($base . '/balance') ?>" data-confirm="Apply this balance change?"><?= csrf_field() ?>
        <div class="form-grid">
          <?= Form::select('direction', 'Action', ['add' => 'Add funds', 'subtract' => 'Subtract funds'], 'add') ?>
          <?= Form::input('amount', 'Amount', '', ['type' => 'number', 'step' => '0.0001', 'min' => '0.0001', 'required' => true]) ?>
        </div>
        <?= Form::select('kind', 'Record as', ['manual_adjustment' => 'Manual adjustment', 'deposit' => 'Deposit (counts toward total deposits)', 'bonus' => 'Bonus'], 'manual_adjustment') ?>
        <?= Form::input('reason', 'Reason (required, visible to user)', '', ['required' => true, 'maxlength' => 200]) ?>
        <button class="btn btn-primary btn-block" type="submit">Apply</button>
      </form>
      <p class="hint mb-0">Every adjustment creates a ledger transaction with your admin ID and is written to the audit log.</p>
    </div></div>
    <?php endif ?>

    <?php if (can('users.manage')): ?>
    <div class="card mb-2"><div class="card-header"><h2>Edit user</h2></div><div class="card-body">
      <form method="post" action="<?= e($base) ?>"><?= csrf_field() ?>
        <?= Form::input('name', 'Name', $user['name']) ?>
        <?= Form::input('email', 'Email', $user['email'], ['type' => 'email', 'required' => true]) ?>
        <div class="form-grid">
          <?= Form::select('status', 'Status', ['active' => 'Active', 'suspended' => 'Suspended', 'banned' => 'Banned'], $user['status']) ?>
          <?= Form::select('price_level_id', 'Price level', $levels, (string) $user['price_level_id'], ['empty' => 'Default']) ?>
        </div>
        <?= Form::input('custom_discount', 'Custom discount %', $user['custom_discount'], ['type' => 'number', 'step' => '0.01', 'min' => 0, 'max' => 100, 'hint' => 'The larger of price-level and custom discount applies.']) ?>
        <?= Form::toggle('api_enabled', 'API access allowed', (int) $user['api_enabled'] === 1) ?>
        <?= Form::toggle('allow_negative', 'Allow negative balance (credit line)', (int) $user['allow_negative'] === 1) ?>
        <?= Form::textarea('admin_note', 'Internal note', $user['admin_note'], ['rows' => 3]) ?>
        <button class="btn btn-primary btn-block" type="submit">Save</button>
      </form>
    </div></div>

    <div class="card mb-2"><div class="card-header"><h2>Security actions</h2></div><div class="card-body">
      <div class="btn-group mb-2">
        <?php foreach (['revoke_sessions' => 'Sign out sessions', 'reset_2fa' => 'Reset 2FA', 'revoke_api' => 'Revoke API key', 'verify_email' => 'Mark email verified'] as $a => $l): ?>
        <form method="post" action="<?= e($base . '/security') ?>" data-confirm="<?= e($l) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $a ?>"><button class="btn btn-secondary btn-sm" type="submit"><?= e($l) ?></button></form>
        <?php endforeach ?>
      </div>
      <form method="post" action="<?= e($base . '/security') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="set_password">
        <div class="input-group"><input class="input" type="password" name="password" placeholder="New password" autocomplete="new-password" required><button class="btn btn-secondary" type="submit">Set</button></div></form>
    </div></div>

    <div class="card"><div class="card-header"><h2 class="text-danger">Delete user</h2></div><div class="card-body">
      <p class="text-sm text-muted">The account is anonymised and disabled; orders and transactions are kept for accounting.</p>
      <form method="post" action="<?= e($base . '/delete') ?>" data-confirm="Permanently delete this user?"><?= csrf_field() ?>
        <div class="input-group"><input class="input" name="confirm" placeholder="Type <?= e($user['username']) ?>" required><button class="btn btn-danger" type="submit">Delete</button></div></form>
    </div></div>
    <?php endif ?>
  </div>
</div>
