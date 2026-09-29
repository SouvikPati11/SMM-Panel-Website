<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>Manual payments</h1><p>Verify each reference in your bank / UPI / wallet statement before approving.</p></div></div>
<div class="chips"><?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(admin_url('manual-payments?status=' . $k)) ?>"><?= $l ?> <span class="count"><?= (int) ($counts[$k] ?? 0) ?></span></a><?php endforeach ?></div>
<?php if (!$items->items): ?><div class="card"><div class="empty"><?= icon('check-circle') ?><h3>Nothing to review</h3></div></div><?php endif ?>
<div class="grid-2">
<?php foreach ($items->items as $r): ?>
  <div class="card"><div class="card-header"><div><h2><?= e(money($r['amount'])) ?> · <?= e($r['method']) ?></h2><div class="cell-sub">#<?= (int) $r['id'] ?> · <a href="<?= e(admin_url('users/' . $r['user_id'])) ?>"><?= e($r['username']) ?></a> · <?= e(fmt_date($r['created_at'])) ?></div></div><?= status_badge($r['status']) ?></div>
    <div class="card-body">
      <dl class="dl" style="grid-template-columns:120px 1fr"><dt>Reference</dt><dd class="mono"><?= e($r['reference']) ?></dd><dt>Pay-to</dt><dd><?= e($r['account'] ?: '—') ?></dd>
        <?php if ($r['coupon_code']): ?><dt>Promo code</dt><dd><?= e($r['coupon_code']) ?></dd><?php endif ?>
        <?php if ($r['user_note']): ?><dt>User note</dt><dd><?= e($r['user_note']) ?></dd><?php endif ?>
        <?php if ($r['admin_note']): ?><dt>Admin note</dt><dd><?= e($r['admin_note']) ?></dd><?php endif ?>
        <dt>Proof</dt><dd><?php if ($r['proof_path']): ?><a href="<?= e(admin_url('manual-payments/' . $r['id'] . '/proof')) ?>" target="_blank" rel="noopener"><img src="<?= e(admin_url('manual-payments/' . $r['id'] . '/proof')) ?>" alt="Payment proof" style="max-height:160px;border-radius:8px;border:1px solid var(--border)" loading="lazy"></a><?php else: ?>None<?php endif ?></dd></dl>
      <?php if ($r['status'] === 'pending' && can('payments.manage')): ?>
      <div class="grid-2 mt-2">
        <form method="post" action="<?= e(admin_url('manual-payments/' . $r['id'] . '/approve')) ?>" data-confirm="Approve and credit this user?"><?= csrf_field() ?>
          <input class="input mb-1" name="amount" type="number" step="0.01" min="0.01" placeholder="Amount (default <?= e($r['amount']) ?>)">
          <input class="input mb-1" name="note" placeholder="Note (optional)">
          <button class="btn btn-success btn-block" type="submit"><?= icon('check') ?> Approve</button></form>
        <form method="post" action="<?= e(admin_url('manual-payments/' . $r['id'] . '/reject')) ?>" data-confirm="Reject this request?"><?= csrf_field() ?>
          <textarea class="textarea mb-1" name="reason" rows="3" placeholder="Reason shown to the user" required style="min-height:92px"></textarea>
          <button class="btn btn-danger btn-block" type="submit"><?= icon('x') ?> Reject</button></form>
      </div>
      <?php endif ?>
    </div></div>
<?php endforeach ?>
</div>
<?= $items->links(\App\Core\App::request()) ?>
