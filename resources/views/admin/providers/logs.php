<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>Provider API logs</h1><p>Every request to a provider (API keys are never logged).</p></div><a class="btn btn-secondary" href="<?= e(admin_url('providers/logs?errors=1')) ?>">Errors only</a></div>
<div class="card"><div class="table-wrap"><table class="table table-cards">
  <thead><tr><th>Time</th><th>Provider</th><th>Action</th><th>HTTP</th><th>Result</th><th>Request</th><th>Response / error</th><th class="num">ms</th></tr></thead><tbody>
  <?php foreach ($logs->items as $l): ?>
    <tr><td data-label="Time" class="text-sm nowrap"><?= e(fmt_date($l['created_at'], 'M j H:i:s')) ?></td><td data-label="Provider"><?= e($l['provider'] ?? '—') ?></td><td data-label="Action" class="mono"><?= e($l['action']) ?></td><td data-label="HTTP"><?= (int) $l['http_status'] ?: '—' ?></td>
      <td data-label="Result"><?= (int) $l['success'] ? status_badge('ok') : status_badge('error') ?></td>
      <td data-label="Request" class="mono text-xs break" style="max-width:260px"><?= e(str_limit($l['request'], 160)) ?></td>
      <td data-label="Response" class="mono text-xs break" style="max-width:340px"><?= e(str_limit($l['error'] ?: $l['response'], 220)) ?></td><td data-label="ms" class="num"><?= (int) $l['duration_ms'] ?></td></tr>
  <?php endforeach ?><?php if (!$logs->items): ?><tr><td colspan="8" class="text-center text-muted" style="padding:28px">No API calls logged yet.</td></tr><?php endif ?>
</tbody></table></div><?= $logs->links(\App\Core\App::request()) ?></div>
