<?php $this->extend('layouts/admin'); use App\Helpers\Form; ?>
<div class="page-head"><div><h1>Promo codes</h1><p>Codes add a bonus to qualifying deposits. Limits are enforced atomically when the payment completes.</p></div><button class="btn btn-primary" type="button" data-open-dialog="coupon-dialog" data-reset><?= icon('plus') ?> New code</button></div>
<div class="card"><div class="table-wrap"><table class="table table-cards">
  <thead><tr><th>Code</th><th>Bonus</th><th>Gateways</th><th class="num">Min deposit</th><th class="num">Used</th><th class="num">Bonus paid</th><th>Valid</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($coupons as $c): ?>
    <tr><td class="cell-main mono fw-bold"><?= e($c['code']) ?></td>
      <td data-label="Bonus"><?= $c['type'] === 'percent' ? e(rtrim(rtrim($c['value'], '0'), '.')) . '%' : e(money($c['value'])) ?><?= $c['max_discount'] !== null ? ' <span class="text-xs text-muted">max ' . e(money($c['max_discount'])) . '</span>' : '' ?></td>
      <td data-label="Gateways" class="text-sm"><?php if ((int) $c['all_gateways'] === 1): ?>All online<?php else: $names = array_column(array_filter($gateways, static fn ($g) => in_array((int) $g['id'], $links[(int) $c['id']] ?? [], true)), 'name'); ?><?= e(implode(', ', $names) ?: '—') ?><?php endif ?></td>
      <td data-label="Min deposit" class="num"><?= e(money($c['min_deposit'])) ?></td>
      <td data-label="Used" class="num"><?= (int) $c['used_count'] ?><?= $c['usage_limit'] ? ' / ' . (int) $c['usage_limit'] : '' ?> <span class="text-xs text-muted">(<?= (int) $c['per_user_limit'] ?>/user)</span></td>
      <td data-label="Bonus paid" class="num"><?= e(money($c['bonus_total'])) ?></td>
      <td data-label="Valid" class="text-sm"><?= $c['starts_at'] ? e(fmt_date($c['starts_at'], 'M j')) : 'now' ?> → <?= $c['expires_at'] ? e(fmt_date($c['expires_at'], 'M j, Y')) : '∞' ?></td>
      <td data-label="Status"><?= $c['expires_at'] && strtotime($c['expires_at'] . ' UTC') < time() ? status_badge('expired') : status_badge($c['status']) ?></td>
      <td class="actions"><button class="btn btn-ghost btn-sm" type="button" data-open-dialog="coupon-dialog" data-fill="<?= json_attr(['id' => $c['id'], 'code' => $c['code'], 'type' => $c['type'], 'value' => $c['value'], 'min_deposit' => $c['min_deposit'], 'max_discount' => $c['max_discount'], 'usage_limit' => $c['usage_limit'], 'per_user_limit' => $c['per_user_limit'], 'starts_at' => $c['starts_at'] ? fmt_date($c['starts_at'], 'Y-m-d\TH:i') : '', 'expires_at' => $c['expires_at'] ? fmt_date($c['expires_at'], 'Y-m-d\TH:i') : '', 'status' => $c['status'], 'gateway_scope' => (int) $c['all_gateways'] === 1 ? 'all' : 'selected', 'payment_methods' => $links[(int) $c['id']] ?? []]) ?>"><?= icon('edit') ?></button>
        <form class="inline-form" method="post" action="<?= e(admin_url('coupons/' . $c['id'] . '/delete')) ?>" data-confirm="Delete this code?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash') ?></button></form></td></tr>
  <?php endforeach ?><?php if (!$coupons): ?><tr><td colspan="9" class="text-center text-muted" style="padding:28px">No promo codes yet.</td></tr><?php endif ?>
</tbody></table></div></div>
<dialog class="modal" id="coupon-dialog"><div class="modal-head"><h3>Promo code</h3><button class="btn btn-ghost btn-icon btn-sm" type="button" data-close-dialog><?= icon('x') ?></button></div><div class="modal-body">
  <form method="post" action="<?= e(admin_url('coupons/save')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="">
    <div class="form-grid"><?= Form::input('code', 'Code', '', ['required' => true, 'maxlength' => 40]) ?><?= Form::select('type', 'Type', ['percent' => 'Percentage bonus', 'fixed' => 'Fixed bonus'], 'percent') ?>
    <?= Form::input('value', 'Value', '', ['type' => 'number', 'step' => '0.01', 'required' => true]) ?><?= Form::input('max_discount', 'Max bonus (optional)', '', ['type' => 'number', 'step' => '0.01']) ?>
    <?= Form::input('min_deposit', 'Minimum deposit', '0', ['type' => 'number', 'step' => '0.01']) ?><?= Form::input('usage_limit', 'Total uses (empty = unlimited)', '', ['type' => 'number', 'min' => 1]) ?>
    <?= Form::input('per_user_limit', 'Uses per user', '1', ['type' => 'number', 'min' => 1]) ?><?= Form::select('status', 'Status', ['active' => 'Active', 'disabled' => 'Disabled'], 'active') ?>
    <?= Form::input('starts_at', 'Starts', '', ['type' => 'datetime-local']) ?><?= Form::input('expires_at', 'Expires', '', ['type' => 'datetime-local']) ?></div>
    <fieldset class="field coupon-gateways"><legend class="label">Applicable payment gateways</legend>
      <label class="check"><input type="radio" name="gateway_scope" value="all" checked> <span>All online gateways</span></label>
      <label class="check"><input type="radio" name="gateway_scope" value="selected"> <span>Only these gateways:</span></label>
      <div class="coupon-gateway-list">
        <?php foreach ($gateways as $g): ?><label class="check"><input type="checkbox" name="payment_methods[]" value="<?= (int) $g['id'] ?>"> <span><?= e($g['name']) ?><?= $g['status'] !== 'active' ? ' <span class="text-muted">(disabled)</span>' : '' ?></span></label><?php endforeach ?>
        <?php if (!$gateways): ?><p class="hint mb-0">No online payment gateways are configured.</p><?php endif ?>
      </div>
      <div class="hint">Promo codes never apply to manual payments (the field is not shown there and the server refuses it).</div>
    </fieldset>
    <button class="btn btn-primary btn-block" type="submit">Save</button></form>
</div></dialog>
