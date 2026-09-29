<?php $this->extend('layouts/admin'); use App\Helpers\Form; ?>
<div class="page-head"><div><h1>Categories</h1></div><button class="btn btn-primary" type="button" data-open-dialog="cat-dialog" data-reset><?= icon('plus') ?> Add category</button></div>
<div class="card"><div class="table-wrap"><table class="table table-cards">
  <thead><tr><th>Order</th><th>Name</th><th class="num">Services</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($categories as $c): ?>
    <tr><td data-label="Order" class="mono"><?= (int) $c['sort_order'] ?></td><td class="cell-main"><span class="cell-title heading-icon"><?= \App\Helpers\Platforms::icon(\App\Helpers\Platforms::detect($c['name'])) ?> <span><?= e($c['name']) ?></span></span><div class="cell-sub">/<?= e($c['slug']) ?></div></td>
      <td data-label="Services" class="num"><a href="<?= e(admin_url('services?category=' . $c['id'])) ?>"><?= (int) $c['active'] ?> / <?= (int) $c['services'] ?></a></td><td data-label="Status"><?= status_badge($c['status']) ?></td>
      <td class="actions"><button class="btn btn-ghost btn-sm" type="button" data-open-dialog="cat-dialog" data-fill="<?= json_attr(['id' => $c['id'], 'name' => $c['name'], 'slug' => $c['slug'], 'sort_order' => $c['sort_order'], 'status' => $c['status'], 'description' => $c['description']]) ?>"><?= icon('edit') ?></button>
        <form class="inline-form" method="post" action="<?= e(admin_url('categories/' . $c['id'] . '/delete')) ?>" data-confirm="Delete category?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash') ?></button></form></td></tr>
  <?php endforeach ?><?php if (!$categories): ?><tr><td colspan="5" class="text-center text-muted" style="padding:28px">No categories yet.</td></tr><?php endif ?>
</tbody></table></div></div>
<dialog class="modal" id="cat-dialog"><div class="modal-head"><h3>Category</h3><button class="btn btn-ghost btn-icon btn-sm" type="button" data-close-dialog><?= icon('x') ?></button></div><div class="modal-body">
  <form method="post" action="<?= e(admin_url('categories/save')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="">
    <?= Form::input('name', 'Name', '', ['required' => true]) ?>
    <div class="form-grid"><?= Form::input('slug', 'Slug', '', ['hint' => 'Auto if empty']) ?><?= Form::input('sort_order', 'Sort order', '0', ['type' => 'number']) ?></div>
    <?= Form::select('status', 'Status', ['active' => 'Active', 'hidden' => 'Hidden'], 'active') ?>
    <?= Form::input('description', 'Description', '') ?>
    <button class="btn btn-primary btn-block" type="submit">Save</button></form>
</div></dialog>
