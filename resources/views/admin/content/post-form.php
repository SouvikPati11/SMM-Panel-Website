<?php $this->extend('layouts/admin'); use App\Helpers\Form; $p = $post ?? []; ?>
<div class="page-head"><div><ol class="breadcrumb"><li><a href="<?= e(admin_url('blog')) ?>">Blog</a></li><li><?= e($p['title'] ?? 'New') ?></li></ol><h1><?= e($title) ?></h1></div>
  <div class="btn-group"><?php if ($post && $p['status'] === 'published' && $p['published_at'] <= now()): ?><a class="btn btn-secondary" href="<?= e(url('/blog/' . $p['slug'])) ?>" target="_blank" rel="noopener"><?= icon('external') ?> View</a><?php endif ?>
  <?php if ($post): ?><form method="post" action="<?= e(admin_url('blog/' . $p['id'] . '/delete')) ?>" data-confirm="Delete post?"><?= csrf_field() ?><button class="btn btn-ghost" type="submit"><?= icon('trash') ?></button></form><?php endif ?></div></div>
<form method="post" action="<?= e(admin_url('blog/save')) ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($p['id'] ?? 0) ?>">
<div class="grid-main">
  <div class="card"><div class="card-body">
    <?= Form::input('title', 'Title', $p['title'] ?? '', ['required' => true]) ?>
    <?= Form::input('slug', 'Slug', $p['slug'] ?? '', ['attrs' => ['data-slug-from' => '#f_title']]) ?>
    <?= Form::textarea('excerpt', 'Excerpt', $p['excerpt'] ?? '', ['rows' => 2, 'maxlength' => 500, 'hint' => 'Auto-generated from content if empty.']) ?>
    <?= Form::textarea('content', 'Content (HTML)', $p['content'] ?? '', ['rows' => 24, 'textarea_class' => 'code', 'required' => true, 'hint' => 'Use &lt;h2&gt;, &lt;p&gt;, &lt;ul&gt;, &lt;a&gt;, &lt;img&gt;, &lt;blockquote&gt;, &lt;table&gt;. Unsafe markup is stripped.']) ?>
  </div></div>
  <div>
    <div class="card mb-2"><div class="card-body">
      <?= Form::select('status', 'Status', ['draft' => 'Draft', 'published' => 'Published'], $p['status'] ?? 'draft') ?>
      <?= Form::input('published_at', 'Publish date', !empty($p['published_at']) ? fmt_date($p['published_at'], 'Y-m-d\TH:i') : '', ['type' => 'datetime-local', 'hint' => 'Future dates schedule the post.']) ?>
      <?= Form::select('category_id', 'Category', $categories, (string) ($p['category_id'] ?? ''), ['empty' => 'Uncategorised']) ?>
      <?= Form::input('tags', 'Tags (comma separated)', $tags) ?>
      <div class="field"><label>Featured image</label><?php if (!empty($p['featured_image'])): ?><img class="featured-preview" src="<?= e(upload_url($p['featured_image'])) ?>" alt="Current featured image" width="360" height="203"><?= Form::check('remove_image', 'Remove image', false) ?><?php endif ?><input class="input mt-1" type="file" name="featured_image" accept="image/jpeg,image/png,image/webp"><div class="hint">JPG, PNG or WebP. 1200×630 works best (also used when the post is shared).</div></div>
    </div></div>
    <div class="card mb-2"><div class="card-header"><h2>SEO</h2></div><div class="card-body">
      <?= Form::input('seo_title', 'SEO title', $p['seo_title'] ?? '', ['maxlength' => 200, 'hint' => 'Optional; defaults to the post title. About 50–60 characters show in search results.']) ?>
      <?= Form::textarea('seo_description', 'Meta description', $p['seo_description'] ?? '', ['rows' => 3, 'maxlength' => 320, 'hint' => 'Optional; defaults to the excerpt. About 150–160 characters show in search results.']) ?>
    </div></div>
    <button class="btn btn-primary btn-lg btn-block" type="submit">Save post</button>
  </div>
</div></form>
