<?php $this->extend('layouts/admin'); use App\Helpers\Form; ?>
<div class="page-head"><div><h1>FAQ</h1><p>Shown on /faq and the homepage, with FAQPage structured data.</p></div><button class="btn btn-primary" type="button" data-open-dialog="faq-dialog" data-reset><?= icon('plus') ?> Add question</button></div>
<div class="card"><ul class="list-plain list-rows"><?php foreach ($faqs as $f): ?>
  <li><div style="min-width:0"><div class="cell-title"><?= (int) $f['sort_order'] ?>. <?= e($f['question']) ?></div><div class="cell-sub truncate"><?= e($f['answer']) ?></div></div>
    <div class="flex gap-1 items-center"><?= status_badge($f['status']) ?><button class="btn btn-ghost btn-sm" type="button" data-open-dialog="faq-dialog" data-fill="<?= json_attr(['id' => $f['id'], 'question' => $f['question'], 'answer' => $f['answer'], 'sort_order' => $f['sort_order'], 'status' => $f['status']]) ?>"><?= icon('edit') ?></button>
    <form method="post" action="<?= e(admin_url('faq/' . $f['id'] . '/delete')) ?>" data-confirm="Delete?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash') ?></button></form></div></li>
<?php endforeach ?></ul></div>
<dialog class="modal" id="faq-dialog"><div class="modal-head"><h3>FAQ</h3><button class="btn btn-ghost btn-icon btn-sm" type="button" data-close-dialog><?= icon('x') ?></button></div><div class="modal-body">
  <form method="post" action="<?= e(admin_url('faq/save')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="">
    <?= Form::input('question', 'Question', '', ['required' => true]) ?><?= Form::textarea('answer', 'Answer (plain text)', '', ['rows' => 5, 'required' => true]) ?>
    <div class="form-grid"><?= Form::input('sort_order', 'Order', '0', ['type' => 'number']) ?><?= Form::select('status', 'Status', ['active' => 'Active', 'hidden' => 'Hidden'], 'active') ?></div>
    <button class="btn btn-primary btn-block" type="submit">Save</button></form>
</div></dialog>
