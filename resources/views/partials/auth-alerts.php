<?php $flashes = \App\Core\Session::takeFlash(); if ($flashes): ?>
<div class="auth-alerts" role="alert" aria-live="assertive">
<?php foreach ($flashes as $f):
    $type = in_array($f['type'], ['success', 'error', 'warning', 'info'], true) ? $f['type'] : 'info';
    $ic = ['success' => 'check-circle', 'error' => 'x-circle', 'warning' => 'alert', 'info' => 'info'][$type]; ?>
  <div class="alert alert-<?= $type === 'error' ? 'danger' : $type ?>"><?= icon($ic) ?><div><?= e($f['message']) ?></div></div>
<?php endforeach ?>
</div>
<?php endif ?>
