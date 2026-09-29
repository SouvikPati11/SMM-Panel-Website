<?php $this->extend('layouts/admin'); use App\Helpers\Form; ?>
<div class="page-head"><div><h1>Currencies</h1><p>Users can view prices in these currencies. Everything is stored, charged and paid in the base currency <strong><?= e($base['code']) ?></strong>, so a rate only changes what users see.</p></div><button class="btn btn-primary" type="button" data-open-dialog="cur-dialog" data-reset><?= icon('plus') ?> Add currency</button></div>
<?php if (setting('currency_switch_enabled', '1') !== '1'): ?><div class="alert alert-info"><?= icon('info') ?><div>Currency selection is switched off for users (Settings → Currency).</div></div><?php endif ?>
<div class="card"><div class="table-wrap"><table class="table table-cards"><thead><tr><th>Currency</th><th class="num">Rate (per 1 <?= e($base['code']) ?>)</th><th>Example</th><th class="num">Users</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($currencies as $c): ?><tr>
  <td class="cell-main"><span class="cell-title"><?= e($c['code']) ?> <span class="text-muted"><?= e($c['symbol']) ?></span></span><div class="cell-sub"><?= e($c['name']) ?><?= $c['base'] ? ' · base currency' : '' ?></div></td>
  <td data-label="Rate" class="num mono"><?= $c['base'] ? '1' : e(rtrim(rtrim($c['rate'], '0'), '.')) ?></td>
  <td data-label="Example" class="nowrap"><?= e(money_base('10')) ?> = <?= e(\App\Services\CurrencyService::format('10', $c)) ?></td>
  <td data-label="Users" class="num"><?= (int) ($users[$c['code']] ?? 0) ?></td>
  <td data-label="Status"><?= $c['base'] ? '<span class="badge badge-primary no-dot">base</span>' : (!empty($c['enabled']) ? status_badge('active') : status_badge('disabled')) ?></td>
  <td class="actions"><?php if (!$c['base']): ?><button class="btn btn-ghost btn-sm" type="button" data-open-dialog="cur-dialog" aria-label="Edit <?= e($c['code']) ?>" data-fill="<?= json_attr(['original' => $c['code'], 'code' => $c['code'], 'name' => $c['name'], 'symbol' => $c['symbol'], 'position' => $c['position'], 'decimals' => (string) $c['decimals'], 'rate' => rtrim(rtrim($c['rate'], '0'), '.'), 'enabled' => !empty($c['enabled']) ? 1 : 0]) ?>"><?= icon('edit') ?></button><?php else: ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('settings?tab=currency')) ?>">Settings</a><?php endif ?></td>
</tr><?php endforeach ?>
</tbody></table></div></div>
<dialog class="modal" id="cur-dialog"><div class="modal-head"><h3>Display currency</h3><button class="btn btn-ghost btn-icon btn-sm" type="button" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div><div class="modal-body">
  <form method="post" action="<?= e(admin_url('currencies/save')) ?>"><?= csrf_field() ?><input type="hidden" name="original" value="">
    <div class="form-grid"><?= Form::input('code', 'ISO code', '', ['required' => true, 'maxlength' => 3, 'placeholder' => 'EUR']) ?><?= Form::input('name', 'Name', '', ['maxlength' => 60, 'placeholder' => 'Euro']) ?></div>
    <div class="form-grid"><?= Form::input('symbol', 'Symbol', '', ['required' => true, 'maxlength' => 8, 'placeholder' => '€']) ?><?= Form::select('position', 'Symbol position', ['before' => 'Before (€10.00)', 'after' => 'After (10.00 €)'], 'before') ?></div>
    <div class="form-grid"><?= Form::input('rate', 'Units per 1 ' . e($base['code']), '', ['required' => true, 'type' => 'number', 'step' => '0.00000001', 'min' => '0.00000001', 'hint' => 'e.g. 0.92 for EUR if 1 ' . e($base['code']) . ' = 0.92 EUR']) ?><?= Form::select('decimals', 'Decimals', ['0' => '0', '1' => '1', '2' => '2', '3' => '3', '4' => '4'], '2') ?></div>
    <?= Form::toggle('enabled', 'Available to users', true) ?>
    <button class="btn btn-primary btn-block" type="submit">Save</button></form>
</div></dialog>
