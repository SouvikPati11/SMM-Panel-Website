<?php $this->extend('layouts/user'); use App\Helpers\Form; ?>
<div class="page-head"><div><ol class="breadcrumb"><li><a href="<?= e(url('/tickets')) ?>">Tickets</a></li><li>New</li></ol><h1>Open a support ticket</h1></div></div>
<div class="grid-main">
  <div class="card"><div class="card-body">
    <form method="post" action="<?= e(url('/tickets')) ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="form-grid">
        <?= Form::select('category', 'Category', \App\Services\TicketService::CATEGORIES, $category ?: 'order', ['required' => true]) ?>
        <?= Form::input('order_ref', 'Order ID(s)', $orderRef, ['placeholder' => 'e.g. 1234, 1235', 'hint' => 'Optional — helps us find your order faster.']) ?>
      </div>
      <?= Form::input('subject', 'Subject', '', ['required' => true, 'maxlength' => 200]) ?>
      <?= Form::textarea('message', 'Message', '', ['required' => true, 'rows' => 7, 'maxlength' => 5000]) ?>
      <?php if (setting('ticket_attachments', '1') === '1'): ?>
      <div class="field"><label for="attachment">Attachment <span class="text-muted">(optional)</span></label><input class="input" id="attachment" type="file" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf,text/plain"><div class="hint">Images, PDF or text. Max <?= round((int) \App\Core\Config::get('uploads.max_bytes') / 1048576, 1) ?> MB.</div></div>
      <?php endif ?>
      <button class="btn btn-primary" type="submit"><?= icon('send') ?> Submit ticket</button>
    </form>
  </div></div>
  <div class="card"><div class="card-body text-sm">
    <h3>Tips for a fast answer</h3>
    <ul style="padding-left:18px;color:var(--text-2)"><li>Include the order ID(s).</li><li>For payments, include the transaction ID and amount.</li><li>One issue per ticket.</li></ul>
    <p class="mb-0">Common questions are answered in the <a href="<?= e(url('/faq')) ?>">FAQ</a>.</p>
  </div></div>
</div>
