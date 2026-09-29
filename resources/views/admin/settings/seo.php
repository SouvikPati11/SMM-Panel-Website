<?php $this->extend('layouts/admin'); use App\Helpers\Form; $s = fn (string $k) => (string) setting($k); ?>
<div class="page-head"><div><h1>SEO</h1><p><a href="<?= e(url('/sitemap.xml')) ?>" target="_blank" rel="noopener">sitemap.xml</a> · <a href="<?= e(url('/robots.txt')) ?>" target="_blank" rel="noopener">robots.txt</a> are generated automatically.</p></div></div>
<div class="card" style="max-width:860px"><div class="card-body">
  <form method="post" action="<?= e(admin_url('seo')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
    <?= Form::input('seo_title', 'Homepage title', $s('seo_title'), ['required' => true, 'maxlength' => 200, 'hint' => 'Other pages use "Page title — Site name".']) ?>
    <?= Form::textarea('seo_description', 'Default meta description', $s('seo_description'), ['rows' => 3, 'maxlength' => 320]) ?>
    <?= Form::input('seo_keywords', 'Meta keywords (optional)', $s('seo_keywords')) ?>
    <div class="field"><label>Default social share image (1200×630)</label><?php if ($s('seo_og_image')): ?><div class="mb-1"><img src="<?= e(upload_url($s('seo_og_image'))) ?>" alt="" style="max-width:300px;border-radius:8px" width="300" height="158"></div><?php endif ?><input class="input" type="file" name="og_image" accept="image/png,image/jpeg,image/webp"></div>
    <div class="form-grid"><?= Form::input('seo_google_verification', 'Google Search Console verification code', $s('seo_google_verification'), ['hint' => 'Only the content value of the meta tag.']) ?><?= Form::input('seo_bing_verification', 'Bing verification code', $s('seo_bing_verification')) ?></div>
    <?= Form::textarea('seo_robots_extra', 'Extra robots.txt rules', $s('seo_robots_extra'), ['rows' => 4, 'textarea_class' => 'code']) ?>
    <button class="btn btn-primary" type="submit">Save</button></form>
</div></div>
