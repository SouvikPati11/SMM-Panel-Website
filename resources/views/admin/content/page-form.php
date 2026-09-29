<?php $this->extend('layouts/admin'); use App\Helpers\Form; $p = $page ?? []; ?>
<div class="page-head"><div><ol class="breadcrumb"><li><a href="<?= e(admin_url('pages')) ?>">Pages</a></li><li><?= e($p['title'] ?? 'New') ?></li></ol><h1><?= e($title) ?></h1></div>
  <?php if ($page && !(int) $p['is_system']): ?><form method="post" action="<?= e(admin_url('pages/' . $p['id'] . '/delete')) ?>" data-confirm="Delete page?"><?= csrf_field() ?><button class="btn btn-ghost" type="submit"><?= icon('trash') ?> Delete</button></form><?php endif ?></div>
<form method="post" action="<?= e(admin_url('pages/save')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($p['id'] ?? 0) ?>">
<div class="grid-main">
  <div class="card"><div class="card-body">
    <?= Form::input('title', 'Title', $p['title'] ?? '', ['required' => true]) ?>
    <?= Form::input('slug', 'Slug', $p['slug'] ?? '', ['readonly' => (int) ($p['is_system'] ?? 0) === 1, 'attrs' => ['data-slug-from' => '#f_title']]) ?>
    <?= Form::textarea('content', 'Content (HTML)', $p['content'] ?? '', ['rows' => 22, 'textarea_class' => 'code', 'hint' => 'Allowed: headings, paragraphs, lists, links, images, tables, bold/italic. Scripts, styles and event handlers are removed automatically.']) ?>
  </div></div>
  <div><div class="card mb-2"><div class="card-body">
    <?= Form::select('status', 'Status', ['published' => 'Published', 'draft' => 'Draft'], $p['status'] ?? 'published') ?>
    <?= Form::toggle('show_in_footer', 'Show in footer', (int) ($p['show_in_footer'] ?? 0) === 1) ?>
    <?= Form::input('seo_title', 'SEO title', $p['seo_title'] ?? '', ['maxlength' => 200]) ?>
    <?= Form::textarea('seo_description', 'Meta description', $p['seo_description'] ?? '', ['rows' => 3, 'maxlength' => 320]) ?>
  </div></div><button class="btn btn-primary btn-lg btn-block" type="submit">Save page</button></div>
</div></form>
