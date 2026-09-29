<?php $this->extend('layouts/admin'); use App\Helpers\Form; $editing = null; foreach ($roles as $r) { if ((int) $r['id'] === $edit) { $editing = $r; } } $cur = $editing ? ($rolePerms[$editing['id']] ?? []) : []; ?>
<div class="page-head"><div><ol class="breadcrumb"><li><a href="<?= e(admin_url('admins')) ?>">Administrators</a></li><li>Roles</li></ol><h1>Roles & permissions</h1></div></div>
<div class="grid-main">
  <div class="card"><div class="card-header"><h2><?= $editing ? 'Edit role: ' . e($editing['name']) : 'New role' ?></h2><?php if ($editing): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('roles')) ?>">New role</a><?php endif ?></div><div class="card-body">
    <form method="post" action="<?= e(admin_url('roles/save')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <div class="form-grid"><?= Form::input('name', 'Name', $editing['name'] ?? '', ['required' => true]) ?><?= Form::input('description', 'Description', $editing['description'] ?? '') ?></div>
      <?php foreach ($perms as $grp => $items): ?><fieldset><legend><?= e($grp) ?></legend><div class="form-grid"><?php foreach ($items as $name => $label): ?><div class="mb-1"><?= Form::check('permissions[]', e($label) . ' <span class="mono text-xs text-muted">' . e($name) . '</span>', in_array($name, $cur, true), $name) ?></div><?php endforeach ?></div></fieldset><?php endforeach ?>
      <button class="btn btn-primary" type="submit">Save role</button></form>
  </div></div>
  <div class="card"><div class="card-header"><h2>Roles</h2></div><ul class="list-plain list-rows"><?php foreach ($roles as $r): ?>
    <li><div><a class="cell-title" href="<?= e(admin_url('roles?edit=' . $r['id'])) ?>"><?= e($r['name']) ?></a><div class="cell-sub"><?= count($rolePerms[$r['id']] ?? []) ?> permissions · <?= (int) $r['admins'] ?> admins</div></div>
      <form method="post" action="<?= e(admin_url('roles/' . $r['id'] . '/delete')) ?>" data-confirm="Delete role?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash') ?></button></form></li><?php endforeach ?></ul></div>
</div>
