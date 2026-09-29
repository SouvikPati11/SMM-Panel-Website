<?php $this->extend('layouts/public'); ?>
<section class="page-hero">
  <div class="container container-sm">
    <ol class="breadcrumb"><li><a href="<?= e(url('/')) ?>">Home</a></li><li>FAQ</li></ol>
    <h1>Frequently asked questions</h1>
    <p>Can't find your answer? <a href="<?= e(url(auth_user() ? '/tickets/new' : '/contact')) ?>">Contact support</a>.</p>
  </div>
</section>
<div class="container container-sm">
  <div class="faq-list">
    <?php foreach ($faqs as $f): ?>
      <details><summary><?= e($f['question']) ?> <?= icon('chevron-down') ?></summary><div class="answer"><?= nl2br(e($f['answer'])) ?></div></details>
    <?php endforeach ?>
    <?php if (!$faqs): ?><div class="card"><div class="empty"><h3>No FAQs yet</h3></div></div><?php endif ?>
  </div>
</div>
