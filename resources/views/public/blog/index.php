<?php $this->extend('layouts/public'); ?>
<section class="page-hero">
  <div class="container">
    <ol class="breadcrumb"><li><a href="<?= e(url('/')) ?>">Home</a></li><li><a href="<?= e(url('/blog')) ?>">Blog</a></li><?php if ($heading !== 'Blog'): ?><li><?= e($heading) ?></li><?php endif ?></ol>
    <h1><?= e($heading) ?></h1>
    <p><?= e($description) ?></p>
  </div>
</section>
<div class="container">
  <?php if ($categories): ?>
  <div class="chips">
    <a class="chip <?= $heading === 'Blog' ? 'active' : '' ?>" href="<?= e(url('/blog')) ?>">All</a>
    <?php foreach ($categories as $c): ?><a class="chip <?= $heading === $c['name'] ? 'active' : '' ?>" href="<?= e(url('/blog/category/' . $c['slug'])) ?>"><?= e($c['name']) ?> <span class="count"><?= (int) $c['n'] ?></span></a><?php endforeach ?>
  </div>
  <?php endif ?>
  <?php if (!$posts->items): ?>
    <div class="card"><div class="empty"><?= icon('book') ?><h3>No articles yet</h3><p>Check back soon.</p></div></div>
  <?php else: ?>
  <div class="blog-grid">
    <?php foreach ($posts->items as $p): ?>
    <a class="post-card" href="<?= e(url('/blog/' . $p['slug'])) ?>">
      <?php if ($p['featured_image']): ?><img class="thumb" src="<?= e(upload_url($p['featured_image'])) ?>" alt="" loading="lazy" width="400" height="225"><?php else: ?><div class="thumb"></div><?php endif ?>
      <div class="body">
        <div class="meta-line mb-1"><span><?= e(fmt_date($p['published_at'], 'M j, Y')) ?></span><?php if ($p['category']): ?><span>· <?= e($p['category']) ?></span><?php endif ?></div>
        <h3><?= e($p['title']) ?></h3>
        <p><?= e(str_limit($p['excerpt'], 140)) ?></p>
      </div>
    </a>
    <?php endforeach ?>
  </div>
  <?= $posts->links(\App\Core\App::request()) ?>
  <?php endif ?>
</div>
