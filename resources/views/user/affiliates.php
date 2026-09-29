<?php $this->extend('layouts/user'); use App\Core\Money; $min = (string) setting('referral_min_withdrawal', '0'); ?>
<div class="page-head"><div><h1>Affiliate program</h1><p>Earn <strong><?= e(rtrim(rtrim((string) setting('referral_percent', '0'), '0'), '.')) ?>%</strong> of every deposit made by users you refer — for life.</p></div></div>
<div class="card mb-2"><div class="card-body">
  <div class="label">Your referral link</div>
  <div class="copy-box"><span><?= e($link) ?></span><button class="btn btn-primary btn-sm" type="button" data-copy="<?= e($link) ?>"><?= icon('copy') ?> <span class="copy-label">Copy link</span></button></div>
</div></div>
<div class="stats" style="grid-template-columns:repeat(3,minmax(0,1fr))">
  <div class="stat"><div class="stat-icon info"><?= icon('users') ?></div><div><div class="stat-label">Referrals</div><div class="stat-value"><?= number_format($stats['referrals']) ?></div></div></div>
  <div class="stat"><div class="stat-icon success"><?= icon('trend') ?></div><div><div class="stat-label">Total earned</div><div class="stat-value"><?= e(money($stats['earned'])) ?></div></div></div>
  <div class="stat"><div class="stat-icon"><?= icon('wallet') ?></div><div><div class="stat-label">Available</div><div class="stat-value"><?= e(money($stats['available'])) ?></div>
    <form method="post" action="<?= e(url('/affiliates/withdraw')) ?>" class="mt-1"><?= csrf_field() ?><button class="btn btn-soft btn-sm" type="submit" <?= Money::cmp($stats['available'], $min) < 0 || !Money::isPositive($stats['available']) ? 'disabled' : '' ?>>Move to balance</button></form>
    <div class="stat-meta">Minimum <?= e(money($min)) ?></div></div></div>
</div>
<div class="grid-main">
  <div class="card"><div class="card-header"><h2>Commission history</h2></div>
    <?php if (!$commissions->items): ?><div class="empty"><?= icon('gift') ?><h3>No commissions yet</h3><p>Share your link — you earn when your referrals add funds.</p></div><?php else: ?>
    <div class="table-wrap"><table class="table table-cards"><thead><tr><th>Date</th><th>User</th><th class="num">Deposit</th><th class="num">Commission</th></tr></thead><tbody>
      <?php foreach ($commissions->items as $c): ?><tr><td data-label="Date" class="text-sm"><?= e(fmt_date($c['created_at'])) ?></td><td data-label="User"><?= e(mb_substr($c['username'], 0, 2)) ?>•••</td><td data-label="Deposit" class="num"><?= e(money($c['base_amount'])) ?></td><td data-label="Commission" class="num text-success fw-bold">+<?= e(money($c['commission'], 4)) ?></td></tr><?php endforeach ?>
    </tbody></table></div>
    <?= $commissions->links(\App\Core\App::request()) ?>
    <?php endif ?>
  </div>
  <div class="card"><div class="card-header"><h2>Recent referrals</h2></div>
    <?php if (!$referrals): ?><div class="empty" style="padding:24px">No referrals yet.</div><?php else: ?>
    <ul class="list-plain list-rows"><?php foreach ($referrals as $r): ?><li><span><?= e(mb_substr($r['username'], 0, 2)) ?>•••</span><span class="text-sm text-muted"><?= e(fmt_date($r['created_at'], 'M j, Y')) ?></span></li><?php endforeach ?></ul>
    <?php endif ?>
    <div class="card-footer text-xs text-muted">Self-referrals and referrals from the same network are not eligible.</div>
  </div>
</div>
