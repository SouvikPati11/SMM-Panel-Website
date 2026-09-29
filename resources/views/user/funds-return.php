<?php $this->extend('layouts/user'); ?>
<div class="card" style="max-width:560px;margin:24px auto"><div class="card-body text-center" style="padding:36px 24px">
  <?php if ($payment['status'] === 'completed'): ?>
    <div class="stat-icon success" style="margin:0 auto 14px;width:56px;height:56px"><?= icon('check-circle') ?></div>
    <h1>Payment confirmed</h1>
    <p class="text-muted"><?= e(money($payment['amount'])) ?> has been added to your balance<?= \App\Core\Money::isPositive((string) $payment['bonus_amount']) ? ' plus a ' . e(money($payment['bonus_amount'])) . ' bonus' : '' ?>.</p>
    <a class="btn btn-primary" href="<?= e(url('/order')) ?>">Place an order</a>
  <?php elseif ($payment['status'] === 'pending'): ?>
    <div class="stat-icon warning" style="margin:0 auto 14px;width:56px;height:56px"><?= icon('clock') ?></div>
    <h1>Waiting for confirmation</h1>
    <p class="text-muted">We haven't received confirmation from the payment provider yet. Crypto payments can take a few minutes to confirm on the network. Your balance is credited automatically — you can safely leave this page.</p>
    <div class="btn-group" style="justify-content:center"><a class="btn btn-secondary" href="<?= e(url('/funds/return/' . $payment['id'])) ?>"><?= icon('refresh') ?> Check again</a><?php if ($payment['pay_url']): ?><a class="btn btn-primary" href="<?= e($payment['pay_url']) ?>" rel="noopener">Return to payment page</a><?php endif ?></div>
  <?php else: ?>
    <div class="stat-icon danger" style="margin:0 auto 14px;width:56px;height:56px"><?= icon('x-circle') ?></div>
    <h1>Payment <?= e($payment['status']) ?></h1>
    <p class="text-muted">This payment was not completed. No funds were taken from your balance. If you did send money, please open a support ticket with your transaction ID.</p>
    <div class="btn-group" style="justify-content:center"><a class="btn btn-primary" href="<?= e(url('/funds')) ?>">Try again</a><a class="btn btn-secondary" href="<?= e(url('/tickets/new?category=payment')) ?>">Contact support</a></div>
  <?php endif ?>
  <p class="text-xs text-muted mt-3 mb-0">Payment #<?= (int) $payment['id'] ?> · <?= e(\App\Services\PaymentService::gatewayLabel($payment['gateway'])) ?></p>
</div></div>
