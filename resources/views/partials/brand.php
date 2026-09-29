<?php $logo = setting('site_logo'); ?>
<?php if ($logo): ?><img src="<?= e(upload_url($logo)) ?>" alt="<?= e(site_name()) ?>" width="140" height="32"><?php else: ?><span class="brand-mark"><?= e(mb_strtoupper(mb_substr(site_name(), 0, 1))) ?></span><span><?= e(site_name()) ?></span><?php endif ?>
