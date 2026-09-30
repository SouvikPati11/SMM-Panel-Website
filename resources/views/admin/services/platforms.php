<?php $this->extend('layouts/admin'); use App\Helpers\Platforms; ?>
<div class="page-head"><div><ol class="breadcrumb"><li><a href="<?= e(admin_url('categories')) ?>">Categories</a></li><li>Platforms</li></ol><h1>Platforms</h1>
  <p>Turn a platform off to hide its categories and services from customers (New Order shortcuts, service list, API list). Nothing is deleted: categories, services, orders and running subscriptions stay as they are, and turning it back on restores everything.</p></div></div>
<form method="post" action="<?= e(admin_url('platforms/save')) ?>">
  <?= csrf_field() ?>
  <div class="card mb-2"><div class="table-wrap"><table class="table table-cards platform-table">
    <thead><tr><th>Platform</th><th>Used by</th><th>New Order card</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($platforms as $key => $p): $u = $usage[$key] ?? ['categories' => 0, 'services' => 0]; $on = $p['status'] === 'active'; ?>
      <tr class="<?= $on ? '' : 'is-off' ?>">
        <td class="cell-main"><div class="platform-row"><span class="platform-row-icon"><?= Platforms::icon($key) ?></span>
          <div class="platform-row-name"><label class="sr-only" for="pf-name-<?= e($key) ?>">Name of <?= e($p['name']) ?></label><input class="input input-sm" id="pf-name-<?= e($key) ?>" name="name[<?= e($key) ?>]" value="<?= e($p['name']) ?>" maxlength="60" required><div class="cell-sub mono"><?= e($key) ?></div></div></div></td>
        <td data-label="Used by" class="text-sm nowrap"><?= (int) $u['categories'] ?> <?= (int) $u['categories'] === 1 ? 'category' : 'categories' ?> · <?= (int) $u['services'] ?> <?= (int) $u['services'] === 1 ? 'service' : 'services' ?></td>
        <td data-label="New Order card"><label class="switch"><input type="checkbox" name="shortcut[<?= e($key) ?>]" value="1"<?= $p['shortcut'] ? ' checked' : '' ?> aria-label="Show <?= e($p['name']) ?> as a New Order card"> <span class="text-sm">Own card</span></label></td>
        <td data-label="Status"><label class="switch"><input type="checkbox" name="enabled[<?= e($key) ?>]" value="1"<?= $on ? ' checked' : '' ?> aria-label="<?= e($p['name']) ?> on or off"> <span class="text-sm platform-state"><?= $on ? '<span class="badge badge-success">On</span>' : '<span class="badge badge-muted">Off</span>' ?></span></label></td>
      </tr>
    <?php endforeach ?>
    </tbody></table></div></div>
  <p class="hint">"Own card" shows the platform as its own shortcut on New Order; platforms without a card appear under <strong>Other</strong>. Categories get their platform in <a href="<?= e(admin_url('categories')) ?>">Categories</a>.</p>
  <button class="btn btn-primary" type="submit"><?= icon('check') ?> Save platforms</button>
</form>
