<?php $this->extend('layouts/admin'); ?>
<div class="page-head"><div><h1>Cron tasks</h1><p>Shared hosting has no background workers — these PHP scripts are run by cPanel/Hostinger cron.</p></div></div>
<div class="card mb-2"><div class="card-header"><h2>Recommended: one cron line every minute</h2></div><div class="card-body">
  <p class="text-sm">Add this in cPanel → Cron Jobs (or Hostinger → Advanced → Cron Jobs). <code>run.php</code> runs each task on its own schedule and uses database locks so runs never overlap.</p>
  <div class="copy-box"><span>* * * * * <?= e($php) ?> <?= e($base) ?>/cron/run.php &gt;/dev/null 2&gt;&amp;1</span><button class="btn btn-ghost btn-sm" type="button" data-copy="* * * * * <?= e($php) ?> <?= e($base) ?>/cron/run.php >/dev/null 2>&1"><?= icon('copy') ?></button></div>
  <p class="hint">If your host enforces a 5-minute minimum, use <code>*/5 * * * *</code>. The PHP path may differ (e.g. <code>/usr/local/bin/php</code> or <code>/opt/alt/php82/usr/bin/php</code>). See docs/cron.md for per-task lines.</p>
</div></div>
<div class="card mb-2"><div class="table-wrap"><table class="table table-cards"><thead><tr><th>Task</th><th>Schedule</th><th>Last run</th><th>Result</th><th class="num">Duration</th><th></th></tr></thead><tbody>
<?php foreach ($tasks as $t => $c): ?><tr><td class="cell-main"><span class="cell-title mono"><?= e($t) ?></span><div class="cell-sub"><?= e($c['label']) ?></div></td><td data-label="Schedule" class="mono text-sm"><?= e($c['schedule']) ?></td>
  <td data-label="Last run" class="text-sm"><?= $c['last'] ? e(fmt_date($c['last']['started_at'])) : 'Never' ?></td><td data-label="Result"><?= $c['stale'] ? '<span class="badge badge-danger">Stale</span>' : status_badge($c['last']['status']) ?></td><td data-label="Duration" class="num"><?= $c['last'] ? (int) $c['last']['duration_ms'] . ' ms' : '—' ?></td>
  <td class="actions"><form method="post" action="<?= e(admin_url('cron/' . $t . '/run')) ?>"><?= csrf_field() ?><button class="btn btn-soft btn-sm" type="submit">Run now</button></form></td></tr><?php endforeach ?>
</tbody></table></div></div>
<div class="card"><div class="card-header"><h2>Recent runs</h2></div><div class="table-wrap"><table class="table table-cards"><thead><tr><th>Task</th><th>Started</th><th>Status</th><th>Output</th></tr></thead><tbody>
<?php foreach ($runs as $r): ?><tr><td data-label="Task" class="mono"><?= e($r['task']) ?></td><td data-label="Started" class="text-sm nowrap"><?= e(fmt_date($r['started_at'], 'M j H:i:s')) ?></td><td data-label="Status"><?= status_badge($r['status']) ?></td><td class="cell-main mono text-xs break"><?= e(str_limit($r['output'], 240)) ?></td></tr><?php endforeach ?>
<?php if (!$runs): ?><tr><td colspan="4" class="text-center text-muted" style="padding:28px">Cron has never run. Add the cron job above.</td></tr><?php endif ?></tbody></table></div></div>
