<?php $this->extend('layouts/admin'); ?>
<div class="card"><div class="empty"><?= icon('shield') ?><h3>Welcome, <?= e(auth_admin()['name'] ?: auth_admin()['username']) ?></h3><p>Use the menu to access the sections your role allows.</p></div></div>
