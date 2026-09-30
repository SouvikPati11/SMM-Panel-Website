<?php $this->extend('layouts/admin'); use App\Core\Money;
$base = admin_url('payments/' . $p['id']); $review = (int) $p['needs_review'] === 1 && $p['status'] !== 'completed';
$history = $meta['review_history'] ?? []; $expected = Money::of(Money::add((string) $p['amount'], (string) $p['fee']), 2); ?>
<div class="page-head">
  <div><ol class="breadcrumb"><li><a href="<?= e(admin_url('payments')) ?>">Payments</a></li><li>#<?= (int) $p['id'] ?></li></ol>
    <h1>Payment #<?= (int) $p['id'] ?> <?= status_badge($p['status']) ?><?= $review ? ' <span class="badge badge-danger">needs review</span>' : '' ?></h1>
    <p>by <a href="<?= e(admin_url('users/' . $p['user_id'])) ?>"><?= e($p['username']) ?></a> · <?= e($p['method'] ?? \App\Services\PaymentService::gatewayLabel($p['gateway'])) ?> · <?= e(fmt_date($p['created_at'])) ?></p></div>
  <?php if (can('payments.manage') && $p['status'] === 'pending' && !$review && in_array($p['gateway'], ['oxapay', 'cryptomus', 'p2gateway'], true) && ($p['gateway_ref'] || $p['merchant_order_id'])): ?><form method="post" action="<?= e($base . '/verify') ?>"><?= csrf_field() ?><button class="btn btn-secondary" type="submit"><?= icon('refresh') ?> Verify with gateway</button></form><?php endif ?>
</div>

<?php if ($review): ?>
<div class="card mb-2" style="border-color:var(--warning)"><div class="card-body">
  <div class="alert alert-warning"><?= icon('alert') ?><div><div class="alert-title">Held for review — nothing has been credited</div><?= e($meta['review'] ?? 'The gateway response did not match this payment.') ?><br>
    Requested <strong><?= e(money($expected)) ?></strong><?= Money::isPositive((string) $p['fee']) ? ' (incl. ' . e(money($p['fee'])) . ' fee)' : '' ?> · gateway confirmed <strong><?= $p['verified_amount'] !== null ? e(money($p['verified_amount'])) : 'no amount' ?></strong><?= $p['utr'] ? ' · UTR <span class="mono">' . e($p['utr']) . '</span>' : '' ?>.
    Check the payment in the gateway dashboard before deciding.</div></div>
  <?php if (can('payments.manage')): ?>
  <div class="grid-3">
    <form method="post" action="<?= e($base . '/review/approve') ?>" data-confirm="Credit this amount to the user's balance?"><?= csrf_field() ?>
      <div class="field"><label for="rv-amount">Amount to credit</label><input class="input" id="rv-amount" name="amount" type="number" step="0.01" min="0.01" max="<?= e($maxApprovable) ?>" value="<?= e($suggested) ?>" required inputmode="decimal"><div class="hint">Max <?= e(money($maxApprovable)) ?>. Promo bonus and referral commission follow this amount.</div></div>
      <div class="field"><label for="rv-note">Note (audit log)</label><input class="input" id="rv-note" name="note" required minlength="3" maxlength="500" placeholder="What you verified"></div>
      <button class="btn btn-success btn-block" type="submit"><?= icon('check') ?> Approve & credit</button></form>
    <form method="post" action="<?= e($base . '/review/reject') ?>" data-confirm="Reject this payment? It will never be credited automatically."><?= csrf_field() ?>
      <div class="field"><label for="rv-reason">Reason (shown to the user)</label><textarea class="textarea" id="rv-reason" name="reason" rows="3" required minlength="3" maxlength="500" style="min-height:92px"></textarea></div>
      <button class="btn btn-danger btn-block" type="submit"><?= icon('x') ?> Reject</button></form>
    <form method="post" action="<?= e($base . '/review/release') ?>" data-confirm="Clear the hold and re-check with the gateway now?"><?= csrf_field() ?>
      <p class="text-sm">The gateway corrected its record, or the mismatch was temporary: clear the hold and re-verify now. It is credited only if the gateway confirms the exact amount; otherwise it is held again.</p>
      <div class="field"><label for="rv-rnote">Note (audit log)</label><input class="input" id="rv-rnote" name="note" required minlength="3" maxlength="500"></div>
      <button class="btn btn-secondary btn-block" type="submit"><?= icon('refresh') ?> Re-check & release</button></form>
  </div>
  <?php endif ?>
</div></div>
<?php endif ?>

<div class="grid-main">
  <div>
    <div class="card mb-2"><div class="card-body"><dl class="dl">
      <dt>Amount</dt><dd><strong><?= e(money($p['amount'])) ?></strong><?= Money::isPositive((string) $p['fee']) ? ' + ' . e(money($p['fee'])) . ' fee' : '' ?> <span class="text-muted"><?= e($p['currency']) ?></span></dd>
      <dt>Verified by gateway</dt><dd><?= $p['verified_amount'] !== null ? e(money($p['verified_amount'])) : '—' ?></dd>
      <dt>Promo bonus</dt><dd><?= Money::isPositive((string) $p['bonus_amount']) ? e(money($p['bonus_amount'])) : '—' ?></dd>
      <dt>Gateway bonus</dt><dd><?= Money::isPositive((string) ($p['gw_bonus_amount'] ?? '0')) ? e(money($p['gw_bonus_amount'])) : '—' ?><?php if ($p['gw_bonus_percent'] !== null && $p['status'] !== 'completed'): ?><div class="cell-sub">Terms: <?= e(rtrim(rtrim((string) $p['gw_bonus_percent'], '0'), '.')) ?>% + <?= e(money($p['gw_bonus_fixed'])) ?><?= $p['gw_bonus_min'] !== null ? ' from ' . e(money($p['gw_bonus_min'])) : '' ?></div><?php endif ?></dd>
      <dt>Merchant order ID</dt><dd class="mono break"><?= e($p['merchant_order_id'] ?: '—') ?></dd>
      <dt>Gateway reference</dt><dd class="mono break"><?= e($p['gateway_ref'] ?: '—') ?></dd>
      <dt>UTR</dt><dd class="mono"><?= e($p['utr'] ?: '—') ?></dd>
      <dt>Gateway status</dt><dd><?= e($p['gateway_status'] ?: '—') ?></dd>
      <dt>Expires / completed</dt><dd><?= e(fmt_date($p['expires_at'])) ?> / <?= e(fmt_date($p['completed_at'])) ?></dd>
      <?php if (!empty($meta['error'])): ?><dt>Error</dt><dd class="text-danger"><?= e($meta['error']) ?></dd><?php endif ?>
    </dl></div></div>
    <div class="card mb-2"><div class="card-header"><h2>Ledger entries</h2></div><div class="table-wrap"><table class="table">
      <thead><tr><th>Tx</th><th>Type</th><th class="num">Amount</th><th>Date</th></tr></thead><tbody>
      <?php foreach ($ledger as $t): ?><tr><td class="mono">#<?= (int) $t['id'] ?></td><td><?= e($t['type']) ?></td><td class="num"><?= e(money($t['amount'])) ?></td><td class="text-sm"><?= e(fmt_date($t['created_at'])) ?></td></tr><?php endforeach ?>
      <?php if (!$ledger): ?><tr><td colspan="4" class="text-muted">Nothing credited.</td></tr><?php endif ?>
    </tbody></table></div></div>
    <div class="card"><div class="card-header"><h2>Callbacks</h2></div><div class="table-wrap"><table class="table">
      <thead><tr><th>Log</th><th>Result</th><th>IP</th><th>Date</th></tr></thead><tbody>
      <?php foreach ($webhooks as $w): ?><tr><td class="mono">#<?= (int) $w['id'] ?></td><td class="text-sm break"><?= e($w['result']) ?></td><td class="mono text-xs"><?= e($w['ip']) ?></td><td class="text-sm nowrap"><?= e(fmt_date($w['created_at'])) ?></td></tr><?php endforeach ?>
      <?php if (!$webhooks): ?><tr><td colspan="4" class="text-muted">No callbacks received.</td></tr><?php endif ?>
    </tbody></table></div></div>
  </div>
  <div>
    <div class="card"><div class="card-header"><h2>Review history</h2></div><div class="card-body">
      <?php if (!$history): ?><p class="text-muted text-sm">No admin decisions.</p><?php else: ?><ul class="timeline">
      <?php foreach (array_reverse($history) as $h): ?><li><div><strong><?= e(ucfirst((string) $h['action'])) ?></strong><?= isset($h['credited']) ? ' · credited ' . e(money($h['credited'])) . ' (requested ' . e(money($h['requested'])) . ')' : '' ?> <span class="text-muted text-xs">by <?= e($admins[$h['admin_id']] ?? ('admin #' . (int) $h['admin_id'])) ?></span></div>
        <?php if (!empty($h['reason'])): ?><div class="text-xs text-muted">Held because: <?= e($h['reason']) ?></div><?php endif ?>
        <div class="text-sm"><?= e($h['note']) ?></div><div class="text-xs text-muted"><?= e(fmt_date($h['at'], 'M j, Y H:i:s')) ?></div></li><?php endforeach ?>
      </ul><?php endif ?>
    </div></div>
  </div>
</div>
