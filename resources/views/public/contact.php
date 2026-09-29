<?php $this->extend('layouts/public'); use App\Helpers\Form; ?>
<section class="page-hero">
  <div class="container">
    <ol class="breadcrumb"><li><a href="<?= e(url('/')) ?>">Home</a></li><li>Contact</li></ol>
    <h1>Contact us</h1>
    <p>Existing customers get the fastest answers through <a href="<?= e(url('/tickets/new')) ?>">support tickets</a>.</p>
  </div>
</section>
<div class="container">
  <div class="grid-main">
    <div class="card"><div class="card-body">
      <form method="post" action="<?= e(url('/contact')) ?>">
        <?= csrf_field() ?>
        <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <div class="form-grid">
          <?= Form::input('name', 'Your name', '', ['required' => true, 'maxlength' => 100, 'autocomplete' => 'name']) ?>
          <?= Form::input('email', 'Email', '', ['type' => 'email', 'required' => true, 'autocomplete' => 'email']) ?>
        </div>
        <?= Form::input('subject', 'Subject', '', ['required' => true, 'maxlength' => 150]) ?>
        <?= Form::textarea('message', 'Message', '', ['required' => true, 'rows' => 6, 'maxlength' => 3000]) ?>
        <button class="btn btn-primary" type="submit"><?= icon('send') ?> Send message</button>
      </form>
    </div></div>
    <div class="card"><div class="card-body">
      <h3>Other ways to reach us</h3>
      <ul class="list-plain">
        <?php if (setting('contact_email')): ?><li class="mb-1"><?= icon('mail') ?> <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a></li><?php endif ?>
        <?php if (setting('contact_telegram')): ?><li class="mb-1"><?= icon('send') ?> Telegram: <?= e(setting('contact_telegram')) ?></li><?php endif ?>
        <?php if (setting('contact_whatsapp')): ?><li class="mb-1"><?= icon('chat') ?> WhatsApp: <?= e(setting('contact_whatsapp')) ?></li><?php endif ?>
        <?php if (setting('contact_address')): ?><li class="mb-1"><?= icon('home') ?> <?= nl2br(e(setting('contact_address'))) ?></li><?php endif ?>
      </ul>
      <p class="text-muted text-sm mt-2">We typically respond within one business day.</p>
    </div></div>
  </div>
</div>
