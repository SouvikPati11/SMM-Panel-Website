<?php $this->extend('layouts/admin'); use App\Helpers\Form;
$now = now();
$tabs = ['' => ['All', $counts['all_n']], 'published' => ['Published', $counts['published']], 'scheduled' => ['Scheduled', $counts['scheduled']], 'draft' => ['Drafts', $counts['draft']]]; ?>
<div class="page-head"><div><h1>Blog</h1><p><?= number_format((int) $counts['all_n']) ?> <?= (int) $counts['all_n'] === 1 ? 'post' : 'posts' ?> · <?= number_format((int) $counts['published']) ?> live on <a href="<?= e(url('/blog')) ?>" target="_blank" rel="noopener">/blog</a><?= setting('blog_enabled', '1') !== '1' ? ' <span class="badge badge-warning no-dot">Blog is switched off in Settings</span>' : '' ?></p></div><a class="btn btn-primary" href="<?= e(admin_url('blog/create')) ?>"><?= icon('plus') ?> New post</a></div>
<div class="grid-main">
  <div class="card">
    <div class="card-header blog-toolbar">
      <div class="tabs tabs-inline"><?php foreach ($tabs as $k => [$label, $n]): ?><a class="<?= $f['status'] === $k ? 'active' : '' ?>" href="<?= e(admin_url('blog' . ($k !== '' ? '?status=' . $k : ''))) ?>"><?= e($label) ?> <span class="tab-count"><?= (int) $n ?></span></a><?php endforeach ?></div>
      <form method="get" action="<?= e(admin_url('blog')) ?>" class="blog-search"><?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif ?><div class="input-icon"><span class="input-icon-glyph"><?= icon('search') ?></span><input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search title or slug" aria-label="Search posts"></div></form>
    </div>
    <?php if (!$posts->items): ?>
      <div class="empty"><?= icon('book') ?><h3><?= $f['q'] !== '' || $f['status'] !== '' ? 'No posts match' : 'No posts yet' ?></h3><p>Posts you publish appear on <code>/blog</code> and in the sitemap.</p><a class="btn btn-primary mt-1" href="<?= e(admin_url('blog/create')) ?>">Write a post</a></div>
    <?php else: ?>
    <ul class="post-list">
      <?php foreach ($posts->items as $p):
        $scheduled = $p['status'] === 'published' && $p['published_at'] > $now;
        $state = $p['status'] === 'draft' ? ['Draft', 'badge-muted'] : ($scheduled ? ['Scheduled', 'badge-info'] : ['Published', 'badge-success']); ?>
      <li class="post-row">
        <a class="post-thumb" href="<?= e(admin_url('blog/' . $p['id'] . '/edit')) ?>" tabindex="-1" aria-hidden="true"><?php if ($p['featured_image']): ?><img src="<?= e(upload_url($p['featured_image'])) ?>" alt="" width="96" height="54" loading="lazy"><?php else: ?><?= icon('file') ?><?php endif ?></a>
        <div class="post-main">
          <a class="cell-title" href="<?= e(admin_url('blog/' . $p['id'] . '/edit')) ?>"><?= e($p['title']) ?></a>
          <div class="cell-sub truncate">/blog/<?= e($p['slug']) ?></div>
          <div class="post-meta"><span class="badge <?= $state[1] ?>"><?= $state[0] ?></span><span><?= e($p['category'] ?: 'Uncategorised') ?></span><span><?= $p['published_at'] ? ($scheduled ? 'Goes live ' : '') . e(fmt_date($p['published_at'], 'M j, Y H:i')) : 'Not published' ?></span><span><?= number_format((int) $p['views']) ?> <?= (int) $p['views'] === 1 ? 'view' : 'views' ?></span></div>
        </div>
        <div class="post-actions">
          <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('blog/' . $p['id'] . '/edit')) ?>" aria-label="Edit <?= e($p['title']) ?>"><?= icon('edit') ?><span class="hide-mobile">Edit</span></a>
          <?php if ($p['status'] === 'published' && !$scheduled): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/blog/' . $p['slug'])) ?>" target="_blank" rel="noopener" aria-label="View <?= e($p['title']) ?>"><?= icon('external') ?><span class="hide-mobile">View</span></a><?php endif ?>
          <form method="post" action="<?= e(admin_url('blog/' . $p['id'] . '/toggle')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= $p['status'] === 'published' ? 'Unpublish' : 'Publish' ?></button></form>
          <form method="post" action="<?= e(admin_url('blog/' . $p['id'] . '/delete')) ?>" data-confirm="Delete “<?= e($p['title']) ?>”? This cannot be undone."><?= csrf_field() ?><button class="btn btn-ghost btn-sm text-danger" type="submit" aria-label="Delete <?= e($p['title']) ?>"><?= icon('trash') ?></button></form>
        </div>
      </li>
      <?php endforeach ?>
    </ul>
    <?= $posts->links(\App\Core\App::request()) ?>
    <?php endif ?>
  </div>
  <div class="card"><div class="card-header"><h2>Categories</h2><button class="btn btn-ghost btn-sm" type="button" data-open-dialog="bc-dialog" data-reset aria-label="Add category"><?= icon('plus') ?></button></div>
    <ul class="list-plain list-rows"><?php foreach ($cats as $c): ?><li><span class="min-w-0 truncate"><?= e($c['name']) ?> <span class="text-muted text-xs">(<?= (int) $c['n'] ?>)</span></span><span class="flex gap-1"><button class="btn btn-ghost btn-sm" type="button" data-open-dialog="bc-dialog" data-fill="<?= json_attr(['id' => $c['id'], 'name' => $c['name'], 'slug' => $c['slug']]) ?>" aria-label="Edit <?= e($c['name']) ?>"><?= icon('edit') ?></button><form method="post" action="<?= e(admin_url('blog/categories/' . $c['id'] . '/delete')) ?>" data-confirm="Delete category? Its posts become uncategorised."><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit" aria-label="Delete <?= e($c['name']) ?>"><?= icon('trash') ?></button></form></span></li><?php endforeach ?>
    <?php if (!$cats): ?><li class="text-muted">No categories.</li><?php endif ?></ul></div>
</div>
<dialog class="modal" id="bc-dialog"><div class="modal-head"><h3>Blog category</h3><button class="btn btn-ghost btn-icon btn-sm" type="button" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div><div class="modal-body">
  <form method="post" action="<?= e(admin_url('blog/categories/save')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value=""><?= Form::input('name', 'Name', '', ['required' => true]) ?><?= Form::input('slug', 'Slug', '') ?><button class="btn btn-primary btn-block" type="submit">Save</button></form>
</div></dialog>
