<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>Pages</h1><p>Legal pages are editable here. Default text is a template, not legal advice.</p></div><a class="btn btn-primary" href="<?= e(admin_url('pages/create')) ?>"><?= icon('plus') ?> New page</a></div>
<div class="card"><div class="table-wrap"><table class="table table-cards"><thead><tr><th>Title</th><th>URL</th><th>Status</th><th>Footer</th><th>Updated</th><th></th></tr></thead><tbody>
<?php foreach ($pages as $p): $u = in_array($p['slug'], ['about', 'terms', 'privacy', 'refund-policy'], true) ? '/' . $p['slug'] : '/page/' . $p['slug']; ?>
  <tr><td class="cell-main"><a class="cell-title" href="<?= e(admin_url('pages/' . $p['id'] . '/edit')) ?>"><?= e($p['title']) ?></a><?= (int) $p['is_system'] ? ' <span class="badge badge-muted no-dot">system</span>' : '' ?></td>
    <td data-label="URL"><a href="<?= e(url($u)) ?>" target="_blank" rel="noopener" class="mono text-sm"><?= e($u) ?></a></td><td data-label="Status"><?= status_badge($p['status']) ?></td><td data-label="Footer"><?= (int) $p['show_in_footer'] ? 'Yes' : 'No' ?></td><td data-label="Updated" class="text-sm"><?= e(time_ago($p['updated_at'])) ?></td>
    <td class="actions"><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('pages/' . $p['id'] . '/edit')) ?>"><?= icon('edit') ?></a></td></tr>
<?php endforeach ?></tbody></table></div></div>
