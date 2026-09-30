<?php $this->extend('layouts/user'); use App\Core\Money; use App\Services\PaymentService; ?>
<div class="page-head"><div><h1>Add funds</h1><p>Your balance: <strong><?= e(money_base($user['balance'])) ?></strong><?php if (\App\Services\CurrencyService::isConverted()): ?> <span class="text-muted">(≈ <?= e(money($user['balance'])) ?>)</span><?php endif ?></p></div></div>
<?php if (\App\Services\CurrencyService::isConverted()): ?><div class="alert alert-info"><?= icon('info') ?><div>Deposits are paid and credited in <strong><?= e(\App\Services\CurrencyService::base()['code']) ?></strong>, the currency your balance is kept in. Amounts in <?= e(\App\Services\CurrencyService::display()['code']) ?> elsewhere on the site are converted for display only.</div></div><?php endif ?>

<div class="grid-main" id="funds-page">
  <div>
    <?php if (!$methods): ?>
      <div class="card"><div class="empty"><?= icon('wallet') ?><h3>No payment methods available</h3><p>Please contact support to add funds.</p></div></div>
    <?php else: ?>
    <div class="card mb-2">
      <div class="card-header"><h2>1. Choose a payment method</h2></div>
      <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px">
        <?php foreach ($methods as $i => $m): ?>
        <label class="card method-card" data-method="<?= (int) $m['id'] ?>">
          <input type="radio" name="method_select" value="<?= (int) $m['id'] ?>" data-gateway="<?= e($m['gateway']) ?>" data-min="<?= e(Money::of((string) $m['min_amount'], 2)) ?>" data-max="<?= e(Money::of((string) $m['max_amount'], 2)) ?>" data-limits="<?= e('Minimum ' . money_base($m['min_amount']) . ' · maximum ' . money_base($m['max_amount']) . ' with ' . $m['name'] . '.') ?>"<?php $bt = PaymentService::bonusTerms($m); if ($bt['gw_bonus_percent'] !== null): ?> data-bonus-pct="<?= e($bt['gw_bonus_percent']) ?>" data-bonus-fixed="<?= e($bt['gw_bonus_fixed']) ?>" data-bonus-min="<?= e((string) $bt['gw_bonus_min']) ?>"<?php endif ?> <?= $i === 0 ? 'checked' : '' ?>>
          <span class="method-card-text"><strong><?= e($m['name']) ?></strong>
          <span class="text-xs text-muted">Min <?= e(money_base($m['min_amount'])) ?> · max <?= e(money_base($m['max_amount'])) ?><?= Money::isPositive((string) $m['fee_percent']) ? ' · ' . e(rtrim(rtrim($m['fee_percent'], '0'), '.')) . '% fee' : '' ?></span>
          <?php if (($bl = PaymentService::bonusLabel($m)) !== ''): ?><span class="method-bonus"><?= icon('gift') ?> <?= e($bl) ?></span><?php endif ?></span>
        </label>
        <?php endforeach ?>
      </div>
    </div>

    <?php foreach ($methods as $m): ?>
    <div class="card mb-2 method-panel" data-method="<?= (int) $m['id'] ?>" hidden>
      <div class="card-header"><h2>2. <?= e($m['name']) ?></h2></div>
      <div class="card-body">
        <?php if ($m['instructions']): ?><div class="svc-desc mb-2" style="max-height:none"><?= e($m['instructions']) ?></div><?php endif ?>
        <?php if ($m['gateway'] === 'manual'): ?>
          <?php if ($m['account']): ?>
            <div class="field"><div class="label">Pay to</div><div class="copy-box"><span id="acct-<?= (int) $m['id'] ?>"><?= e($m['account']) ?></span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($m['account']) ?>"><?= icon('copy') ?> <span class="copy-label">Copy</span></button></div></div>
          <?php endif ?>
          <?php if ($m['qr_image']): ?><div class="field text-center"><img src="<?= e(upload_url($m['qr_image'])) ?>" alt="Payment QR code" width="220" height="220" style="border-radius:12px;border:1px solid var(--border);background:#fff;padding:8px" loading="lazy"></div><?php endif ?>
          <form method="post" action="<?= e(url('/funds/manual')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="method_id" class="method-id" value="<?= (int) $m['id'] ?>">
            <div class="form-grid">
              <div class="field"><label>Amount paid</label><div class="input-group"><span class="input-prefix"><?= e(setting('currency_symbol', '$')) ?></span><input class="input" name="amount" type="number" step="0.01" min="<?= e(Money::of((string) $m['min_amount'], 2)) ?>" max="<?= e(Money::of((string) $m['max_amount'], 2)) ?>" required inputmode="decimal" placeholder="<?= e(Money::of((string) $m['min_amount'], 2)) ?>"></div><div class="hint">Minimum <?= e(money_base($m['min_amount'])) ?> · maximum <?= e(money_base($m['max_amount'])) ?>.</div><div class="bonus-preview" aria-live="polite" hidden></div></div>
              <div class="field"><label>Transaction / reference ID</label><input class="input" name="reference" required maxlength="120" placeholder="e.g. UTR number"></div>
            </div>
            <div class="field"><label>Payment screenshot <?= (int) $m['require_proof'] === 1 ? '' : '<span class="text-muted">(optional)</span>' ?></label><input class="input" type="file" name="proof" accept="image/jpeg,image/png,image/webp" <?= (int) $m['require_proof'] === 1 ? 'required' : '' ?>><div class="hint">JPG, PNG or WebP, max <?= round((int) \App\Core\Config::get('uploads.max_bytes') / 1048576, 1) ?> MB.</div></div>
            <div class="field"><label>Promo code <span class="text-muted">(optional)</span></label><div class="input-group"><input class="input" name="coupon" maxlength="40" autocomplete="off"><button class="btn btn-secondary" type="button" data-coupon-check="<?= e(url('/funds/coupon')) ?>">Apply</button></div><div class="coupon-result hint"></div></div>
            <?= \App\Helpers\Form::input('note', 'Note (optional)', '', ['maxlength' => 500]) ?>
            <button class="btn btn-primary btn-lg btn-block" type="submit"><?= icon('upload') ?> Submit for verification</button>
          </form>
        <?php endif ?>
      </div>
    </div>
    <?php endforeach ?>

    <div class="card mb-2" id="gateway-form" hidden>
      <div class="card-body">
        <form method="post" action="<?= e(url('/funds')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="method_id" class="method-id" value="">
          <div class="field"><label>Amount</label><div class="input-group"><span class="input-prefix"><?= e(setting('currency_symbol', '$')) ?></span><input class="input" name="amount" type="number" step="0.01" min="0.01" required inputmode="decimal" aria-describedby="gw-limits"></div><div class="hint" id="gw-limits"></div><div class="bonus-preview" aria-live="polite" hidden></div></div>
          <div class="field" data-for-gateway="p2gateway" hidden><label for="customer_mobile">Mobile number</label><input class="input" id="customer_mobile" name="customer_mobile" type="tel" inputmode="tel" maxlength="14" autocomplete="tel" value="<?= e($lastMobile) ?>" placeholder="10-digit mobile number"><div class="hint">Mobile number linked to your UPI app. Payment links expire after 30 minutes.</div></div>
          <div class="field"><label>Promo code <span class="text-muted">(optional)</span></label><div class="input-group"><input class="input" name="coupon" maxlength="40" autocomplete="off"><button class="btn btn-secondary" type="button" data-coupon-check="<?= e(url('/funds/coupon')) ?>">Apply</button></div><div class="coupon-result hint"></div></div>
          <button class="btn btn-primary btn-lg btn-block" type="submit"><?= icon('external') ?> Continue to payment</button>
          <p class="hint text-center">You'll be redirected to the secure payment page. Your balance is credited automatically once the payment is confirmed.</p>
        </form>
      </div>
    </div>
    <?php endif ?>
  </div>

  <div>
    <div class="card mb-2">
      <div class="card-header"><h2>Recent payments</h2></div>
      <?php if (!$payments): ?><div class="empty" style="padding:24px"><p class="mb-0">No payments yet.</p></div><?php else: ?>
      <ul class="list-plain list-rows">
        <?php foreach ($payments as $p): ?>
        <li><div style="min-width:0"><div class="cell-title"><?= e(money_base($p['amount'])) ?> <span class="text-muted text-xs">#<?= (int) $p['id'] ?></span></div><div class="cell-sub truncate"><?= e($p['method'] ?? \App\Services\PaymentService::gatewayLabel($p['gateway'])) ?> · <?= e(time_ago($p['created_at'])) ?></div></div>
          <div class="text-right"><?= status_badge($p['status']) ?><?php if ($p['status'] === 'pending' && $p['pay_url'] && $p['gateway'] !== 'manual'): ?><br><a class="text-xs" href="<?= e($p['pay_url']) ?>" rel="noopener">Pay now</a><?php endif ?></div></li>
        <?php endforeach ?>
      </ul>
      <?php endif ?>
    </div>
    <?php if ($manualRequests): ?>
    <div class="card">
      <div class="card-header"><h2>Manual requests</h2></div>
      <ul class="list-plain list-rows">
        <?php foreach ($manualRequests as $r): ?>
        <li><div style="min-width:0"><div class="cell-title"><?= e(money_base($r['amount'])) ?> · <?= e($r['method']) ?></div><div class="cell-sub truncate">Ref <?= e($r['reference']) ?> · <?= e(time_ago($r['created_at'])) ?></div><?php if ($r['status'] === 'rejected' && $r['admin_note']): ?><div class="text-xs text-danger"><?= e($r['admin_note']) ?></div><?php endif ?></div><?= status_badge($r['status']) ?></li>
        <?php endforeach ?>
      </ul>
    </div>
    <?php endif ?>
  </div>
</div>
