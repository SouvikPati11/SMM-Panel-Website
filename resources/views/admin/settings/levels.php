<?php $this->extend('layouts/admin'); use App\Helpers\Form; ?>
<div class="page-head"><div><h1>Price levels</h1><p>Users move up automatically when their <strong>lifetime credited deposits</strong> reach a level's minimum. Pending, held, failed and rejected deposits do not count. A level without a minimum is manual-only. The larger of level and custom discount applies.</p></div><button class="btn btn-primary" type="button" data-open-dialog="lvl-dialog" data-reset><?= icon('plus') ?> Add level</button></div>
<div class="card"><div class="table-wrap"><table class="table table-cards"><thead><tr><th>Level</th><th class="num">Unlocks at (lifetime deposits)</th><th class="num">Discount</th><th class="num">Users</th><th></th></tr></thead><tbody>
<?php foreach ($levels as $l): ?><tr><td class="cell-main"><span class="cell-title"><?= e($l['name']) ?></span><?php if ($l['description']): ?><div class="cell-sub"><?= e($l['description']) ?></div><?php endif ?></td>
  <td data-label="Unlocks at" class="num nowrap"><?= $l['min_deposit'] !== null ? e(money_base($l['min_deposit'])) : '<span class="badge badge-muted no-dot">manual only</span>' ?></td>
  <td data-label="Discount" class="num"><?= e(rtrim(rtrim((string) $l['discount_percent'], '0'), '.')) ?>%</td>
  <td data-label="Users" class="num"><?= (int) $l['users'] ?><?= (int) $l['manual_users'] ? '<div class="cell-sub">' . (int) $l['manual_users'] . ' manual</div>' : '' ?></td>
  <td class="actions"><button class="btn btn-ghost btn-sm" type="button" data-open-dialog="lvl-dialog" aria-label="Edit <?= e($l['name']) ?>" data-fill="<?= json_attr(['id' => $l['id'], 'name' => $l['name'], 'description' => $l['description'], 'discount_percent' => $l['discount_percent'], 'min_deposit' => $l['min_deposit'] !== null ? rtrim(rtrim((string) $l['min_deposit'], '0'), '.') : '']) ?>"><?= icon('edit') ?></button><form class="inline-form" method="post" action="<?= e(admin_url('price-levels/' . $l['id'] . '/delete')) ?>" data-confirm="Delete level? Its users are re-evaluated automatically."><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit" aria-label="Delete <?= e($l['name']) ?>"><?= icon('trash') ?></button></form></td></tr><?php endforeach ?>
<?php if (!$levels): ?><tr><td colspan="5" class="text-center text-muted" style="padding:28px">No price levels yet.</td></tr><?php endif ?>
</tbody></table></div></div>
<dialog class="modal" id="lvl-dialog"><div class="modal-head"><h3>Price level</h3><button class="btn btn-ghost btn-icon btn-sm" type="button" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div><div class="modal-body">
  <form method="post" action="<?= e(admin_url('price-levels/save')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="">
    <?= Form::input('name', 'Name', '', ['required' => true, 'maxlength' => 60]) ?>
    <?= Form::input('description', 'Description', '', ['maxlength' => 500]) ?>
    <div class="form-grid"><?= Form::input('discount_percent', 'Discount %', '0', ['type' => 'number', 'step' => '0.01', 'min' => 0, 'max' => 100, 'required' => true]) ?>
    <?= Form::input('min_deposit', 'Unlocks after lifetime deposits of (' . e(\App\Services\CurrencyService::base()['code']) . ')', '', ['type' => 'number', 'step' => '0.01', 'min' => 0, 'hint' => 'Total of all credited deposits. Empty = manual-only level. This is not a minimum deposit amount: deposit limits are set per <a href="' . e(admin_url('gateways')) . '">payment gateway</a>.']) ?></div>
    <button class="btn btn-primary btn-block" type="submit">Save</button></form>
</div></dialog>
