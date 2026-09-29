<?php $this->extend('layouts/public'); ?>
<article>
  <section class="page-hero">
    <div class="container container-sm">
      <ol class="breadcrumb"><li><a href="<?= e(url('/')) ?>">Home</a></li><li><a href="<?= e(url('/blog')) ?>">Blog</a></li><?php if ($post['category']): ?><li><a href="<?= e(url('/blog/category/' . $post['category_slug'])) ?>"><?= e($post['category']) ?></a></li><?php endif ?></ol>
      <h1><?= e($post['title']) ?></h1>
      <div class="meta-line"><span><?= e(fmt_date($post['published_at'], 'F j, Y')) ?></span><?php if ($post['author']): ?><span>· <?= e($post['author']) ?></span><?php endif ?><span>· <?= max(1, (int) round(str_word_count(strip_tags($post['content'])) / 220)) ?> min read</span></div>
    </div>
  </section>
  <div class="container container-sm">
    <?php if ($post['featured_image']): ?><img src="<?= e(upload_url($post['featured_image'])) ?>" alt="<?= e($post['title']) ?>" style="border-radius:16px;width:100%;margin-bottom:28px" width="820" height="460"><?php endif ?>
    <div class="prose"><?= $post['content'] /* sanitized */ ?></div>
    <?php if ($tags): ?><div class="chips mt-3"><?php foreach ($tags as $t): ?><a class="chip" href="<?= e(url('/blog/tag/' . $t['slug'])) ?>">#<?= e($t['name']) ?></a><?php endforeach ?></div><?php endif ?>
  </div>
</article>
<?php if ($related): ?>
<section class="section">
  <div class="container">
    <h2 class="mb-2">Keep reading</h2>
    <div class="blog-grid">
      <?php foreach ($related as $p): ?>
      <a class="post-card" href="<?= e(url('/blog/' . $p['slug'])) ?>">
        <?php if ($p['featured_image']): ?><img class="thumb" src="<?= e(upload_url($p['featured_image'])) ?>" alt="" loading="lazy" width="400" height="225"><?php endif ?>
        <div class="body"><div class="meta-line mb-1"><?= e(fmt_date($p['published_at'], 'M j, Y')) ?></div><h3><?= e($p['title']) ?></h3></div>
      </a>
      <?php endforeach ?>
    </div>
  </div>
</section>
<?php endif ?>
