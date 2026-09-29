<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>Users</h1><p><?= number_format($users->total) ?> accounts</p></div><?php if (can('users.manage')): ?><a class="btn btn-primary" href="<?= e(admin_url('users/create')) ?>"><?= icon('plus') ?> Create user</a><?php endif ?></div>
<form class="toolbar" method="get" action="<?= e(admin_url('users')) ?>">
  <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Username, email, ID or IP">
  <select class="select" name="status" data-autosubmit><option value="">Any status</option><?php foreach (['active', 'suspended', 'banned'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach ?></select>
  <select class="select" name="sort" data-autosubmit><option value="">Newest</option><option value="balance"<?= $sort === 'balance' ? ' selected' : '' ?>>Highest balance</option><option value="spent"<?= $sort === 'spent' ? ' selected' : '' ?>>Top spenders</option><option value="login"<?= $sort === 'login' ? ' selected' : '' ?>>Last login</option></select>
  <button class="btn btn-secondary" type="submit"><?= icon('search') ?> Search</button>
</form>
<div class="card">
  <?php if (!$users->items): ?><div class="empty"><?= icon('users') ?><h3>No users found</h3></div><?php else: ?>
  <div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>ID</th><th>User</th><th class="num">Balance</th><th class="num">Spent</th><th>Level</th><th>Status</th><th>Last login</th><th>Joined</th></tr></thead>
    <tbody><?php foreach ($users->items as $u): ?>
      <tr><td data-label="ID" class="mono"><?= (int) $u['id'] ?></td>
        <td class="cell-main"><a class="cell-title" href="<?= e(admin_url('users/' . $u['id'])) ?>"><?= e($u['username']) ?></a><div class="cell-sub"><?= e($u['email']) ?></div></td>
        <td data-label="Balance" class="num fw-bold"><?= e(money($u['balance'])) ?></td>
        <td data-label="Spent" class="num"><?= e(money($u['total_spent'])) ?></td>
        <td data-label="Level"><?= e($u['level'] ?: '—') ?><?= (float) $u['custom_discount'] > 0 ? ' <span class="badge badge-purple no-dot">-' . e($u['custom_discount']) . '%</span>' : '' ?></td>
        <td data-label="Status"><?= status_badge($u['status']) ?></td>
        <td data-label="Last login" class="text-sm nowrap"><?= e(time_ago($u['last_login_at'])) ?></td>
        <td data-label="Joined" class="text-sm nowrap"><?= e(fmt_date($u['created_at'], 'M j, Y')) ?></td></tr>
    <?php endforeach ?></tbody>
  </table></div>
  <div class="flex justify-between items-center wrap" style="padding:0 16px"><span class="text-sm text-muted"><?= e($users->summary()) ?></span><?= $users->links(\App\Core\App::request()) ?></div>
  <?php endif ?>
</div>
