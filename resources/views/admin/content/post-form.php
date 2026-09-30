<?php $this->extend('layouts/admin'); use App\Helpers\Form; $p = $post ?? []; ?>
<div class="page-head"><div><ol class="breadcrumb"><li><a href="<?= e(admin_url('blog')) ?>">Blog</a></li><li><?= e($p['title'] ?? 'New') ?></li></ol><h1><?= e($title) ?></h1></div>
  <div class="btn-group"><?php if ($post && $p['status'] === 'published' && $p['published_at'] <= now()): ?><a class="btn btn-secondary" href="<?= e(url('/blog/' . $p['slug'])) ?>" target="_blank" rel="noopener"><?= icon('external') ?> View</a><?php endif ?>
  <?php if ($post): ?><form method="post" action="<?= e(admin_url('blog/' . $p['id'] . '/delete')) ?>" data-confirm="Delete post?"><?= csrf_field() ?><button class="btn btn-ghost" type="submit"><?= icon('trash') ?></button></form><?php endif ?></div></div>
<form method="post" action="<?= e(admin_url('blog/save')) ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($p['id'] ?? 0) ?>">
<div class="grid-main">
  <div class="card"><div class="card-body">
    <?= Form::input('title', 'Title', $p['title'] ?? '', ['required' => true]) ?>
    <?= Form::input('slug', 'Slug (web address)', $p['slug'] ?? '', ['attrs' => ['data-slug-from' => '#f_title'], 'hint' => 'Created from the title if empty. Changing it later is safe: the old address redirects to the new one.']) ?>
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
    <div class="card mb-2" id="seo"><div class="card-header"><h2>SEO</h2></div><div class="card-body">
      <?php $sp = $p + ['title' => '', 'slug' => '', 'content' => '', 'excerpt' => '']; ?>
      <?php if ($post): ?>
      <div class="serp-preview" aria-label="Search result preview">
        <div class="serp-url"><?= e(\App\Services\BlogSeo::canonical($sp)) ?></div>
        <div class="serp-title"><?= e(\App\Services\BlogSeo::title($sp)) ?></div>
        <div class="serp-desc"><?= e(\App\Services\BlogSeo::description($sp)) ?></div>
      </div>
      <?php endif ?>
      <?= Form::input('seo_title', 'SEO title', $p['seo_title'] ?? '', ['maxlength' => 200, 'hint' => 'Optional, used exactly as typed. Empty: the post title plus the site name. About 50–60 characters show in search results.']) ?>
      <?= Form::textarea('seo_description', 'Meta description', $p['seo_description'] ?? '', ['rows' => 3, 'maxlength' => 320, 'hint' => 'Optional. Empty: the excerpt, or the start of the post, shortened to about 155 characters.']) ?>
      <?= Form::input('seo_keyword', 'Focus keyword', $p['seo_keyword'] ?? '', ['maxlength' => 100, 'hint' => 'The main phrase this post is about. Used for the checks below and the article keywords; it does not guarantee a ranking.']) ?>
      <?= Form::input('canonical_url', 'Canonical URL', $p['canonical_url'] ?? '', ['type' => 'url', 'maxlength' => 500, 'placeholder' => $post ? \App\Services\BlogSeo::url($sp) : 'Empty = this post\'s own address', 'hint' => 'Only if this article was first published at another address. Empty (recommended): the post\'s own URL.']) ?>
      <div class="field"><label>Share image (Open Graph)</label><?php if (!empty($p['og_image'])): ?><img class="featured-preview" src="<?= e(upload_url($p['og_image'])) ?>" alt="Current share image" width="360" height="189"><?= Form::check('remove_og_image', 'Remove share image', false) ?><?php endif ?><input class="input mt-1" type="file" name="og_image" accept="image/jpeg,image/png,image/webp"><div class="hint">Optional. Empty: the featured image is used when the post is shared (1200×630 recommended).</div></div>
      <?= Form::toggle('robots_index', 'Allow search engines to index this post', (int) ($p['robots_index'] ?? 1) === 1, 'Off = noindex: the post stays public but is left out of search results and the sitemap.') ?>
      <?= Form::toggle('robots_follow', 'Allow search engines to follow links in this post', (int) ($p['robots_follow'] ?? 1) === 1) ?>
      <?php if ($post): ?>
      <div class="label mt-2">SEO checks</div>
      <ul class="seo-checks">
        <?php foreach (\App\Services\BlogSeo::keywordChecks($sp) as [$ok, $text]): ?><li class="<?= $ok ? 'ok' : 'todo' ?>"><?= icon($ok ? 'check-circle' : 'alert') ?> <span><?= e($text) ?></span></li><?php endforeach ?>
      </ul>
      <p class="hint mb-0">Guidance only: these checks help search engines understand the post, they do not guarantee rankings.</p>
      <?php endif ?>
    </div></div>
    <button class="btn btn-primary btn-lg btn-block" type="submit">Save post</button>
  </div>
</div></form>
