<?php $this->extend('layouts/public'); ?>
<section class="page-hero">
  <div class="container container-sm">
    <ol class="breadcrumb"><li><a href="<?= e(url('/')) ?>">Home</a></li><li><?= e($page['title']) ?></li></ol>
    <h1><?= e($page['title']) ?></h1>
    <p class="text-sm">Last updated <?= e(fmt_date($page['updated_at'], 'F j, Y')) ?></p>
  </div>
</section>
<div class="container container-sm">
  <article class="prose"><?= $page['content'] /* sanitized by HtmlSanitizer */ ?></article>
</div>
