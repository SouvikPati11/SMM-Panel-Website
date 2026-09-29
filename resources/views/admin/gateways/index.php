<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>Payment gateways</h1><p>Automatic gateways credit balances only after verified webhooks / server-side status checks.</p></div><a class="btn btn-primary" href="<?= e(admin_url('gateways/create')) ?>"><?= icon('plus') ?> Add manual method</a></div>
<div class="alert alert-info"><?= icon('info') ?><div>Webhook URLs to enter in the gateway dashboards:<br><code><?= e(url('/webhooks/oxapay')) ?></code> (OxaPay) · <code><?= e(url('/webhooks/cryptomus')) ?></code> (Cryptomus) · <code><?= e(url('/webhooks/p2gateway')) ?></code> (P2Gateway — set it in the P2Gateway Merchant Dashboard → Webhook URL). OxaPay/Cryptomus URLs are also sent automatically with each invoice.</div></div>
<div class="card"><div class="table-wrap"><table class="table table-cards">
  <thead><tr><th>Name</th><th>Type</th><th class="num">Limits</th><th class="num">Fee</th><th>Setup</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($methods as $m): ?>
    <tr><td class="cell-main"><span class="cell-title"><?= e($m['name']) ?></span></td><td data-label="Type"><?= $m['gateway'] === 'manual' ? 'Manual' : e(\App\Services\PaymentService::gatewayLabel($m['gateway'])) ?></td>
      <td data-label="Limits" class="num text-sm nowrap"><?= e(money($m['min_amount'])) ?> – <?= e(money($m['max_amount'])) ?></td><td data-label="Fee" class="num"><?= e(rtrim(rtrim($m['fee_percent'], '0'), '.')) ?>%</td>
      <td data-label="Setup"><?php if (!$m['implemented']): ?><span class="badge badge-muted">Not implemented</span><?php elseif (!$m['currency_ok']): ?><span class="badge badge-warning">Currency mismatch</span><?php elseif (!$m['configured']): ?><span class="badge badge-warning">Credentials missing</span><?php else: ?><span class="badge badge-success">Ready</span><?php endif ?></td>
      <td data-label="Status"><?= status_badge($m['status']) ?></td><td class="actions"><a class="btn btn-soft btn-sm" href="<?= e(admin_url('gateways/' . $m['id'] . '/edit')) ?>">Configure</a></td></tr>
  <?php endforeach ?>
</tbody></table></div></div>
