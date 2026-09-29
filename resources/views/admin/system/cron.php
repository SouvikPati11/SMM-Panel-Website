<?php $this->extend('layouts/admin');
$cmd = $php . ' ' . $base . '/cron/run.php';
$last = $cronStatus['invoked_at'] ?? null; ?>
<div class="page-head"><div><h1>Cron tasks</h1><p>Shared hosting has no background workers — these tasks are started by your hosting control panel's cron.</p></div></div>

<?php foreach ($problems as $p): ?><div class="alert alert-<?= e($p['level']) ?>"><?= icon('alert') ?><div><?= e($p['text']) ?></div></div><?php endforeach ?>

<div class="grid-2 mb-2">
  <div class="card"><div class="card-header"><h2>Last cron run</h2><?= $last ? (empty($cronStatus['exit_code']) && ($cronStatus['stage'] ?? '') === 'done' ? '<span class="badge badge-success">OK</span>' : (($cronStatus['stage'] ?? '') === 'running' || ($cronStatus['stage'] ?? '') === 'starting' ? '<span class="badge badge-info">running</span>' : '<span class="badge badge-danger">failed</span>')) : '<span class="badge badge-danger">never</span>' ?></div>
    <div class="card-body"><dl class="dl">
      <dt>Last invocation</dt><dd><?= $last ? e(fmt_date($last, 'M j, Y H:i:s')) . ' <span class="text-muted">(' . e(time_ago($last)) . ')</span>' : 'Never' ?></dd>
      <dt>Last success</dt><dd><?= !empty($cronStatus['last_success_at']) ? e(fmt_date($cronStatus['last_success_at'], 'M j, Y H:i:s')) : '—' ?></dd>
      <dt>Last error</dt><dd class="break"><?= !empty($cronStatus['last_error']) ? e(fmt_date($cronStatus['last_error_at'] ?? null, 'M j H:i')) . ' — <span class="text-danger">' . e(str_limit((string) $cronStatus['last_error'], 300)) . '</span>' : '—' ?></dd>
      <dt>Exit code</dt><dd class="mono"><?= isset($cronStatus['exit_code']) ? (int) $cronStatus['exit_code'] : '—' ?></dd>
      <dt>PHP used by cron</dt><dd class="mono break"><?= !empty($cronStatus['php_binary']) ? e($cronStatus['php_binary']) . ' · PHP ' . e($cronStatus['php_version'] ?? '?') . ' · ' . e($cronStatus['sapi'] ?? '?') : '—' ?></dd>
      <dt>Last <code>--check</code></dt><dd class="break"><?= !empty($cronCheck['invoked_at']) ? e(fmt_date($cronCheck['invoked_at'], 'M j H:i')) . ' · ' . (empty($cronCheck['exit_code']) ? '<span class="text-success">all checks passed</span>' : '<span class="text-danger">' . e(str_limit((string) ($cronCheck['error'] ?? 'failed'), 200)) . '</span>') . ' · <span class="mono text-xs">' . e(($cronCheck['php_binary'] ?? '?') . ' · PHP ' . ($cronCheck['php_version'] ?? '?') . ' · ' . ($cronCheck['sapi'] ?? '?')) . '</span>' : '—' ?></dd>
      <dt>Summary</dt><dd class="mono text-xs break"><?= !empty($cronStatus['summary']) ? e(json_encode($cronStatus['summary'])) : '—' ?></dd>
    </dl></div></div>
  <div class="card"><div class="card-header"><h2>Environment check</h2></div>
    <ul class="list-plain list-rows"><?php foreach ($checks as [$label, $ok, $detail]): ?>
      <li><div style="min-width:0"><div class="cell-title"><?= e($label) ?></div><div class="cell-sub break"><?= e($detail) ?></div></div><?= $ok ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">Fix</span>' ?></li>
    <?php endforeach ?></ul>
    <div class="card-body"><p class="hint mb-0">This check runs in the web server's PHP (<?= e(PHP_VERSION . ' · ' . PHP_SAPI) ?>). Cron uses a separate PHP binary; run <code>php cron/run.php --check</code> with the command below to check that one.</p></div></div>
</div>

<div class="card mb-2"><div class="card-header"><h2>Set up the cron job</h2></div><div class="card-body">
  <p class="text-sm">Add <strong>one</strong> cron job that runs every minute (or every 5 minutes if your plan requires it). <code>run.php</code> starts each task on its own schedule, and database locks stop runs from overlapping.</p>
  <div class="field"><label>Command</label>
    <div class="copy-box"><span class="break"><?= e($cmd) ?></span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($cmd) ?>" aria-label="Copy command"><?= icon('copy') ?></button></div></div>
  <ul class="text-sm">
    <li><strong>Hostinger:</strong> hPanel → Advanced → Cron Jobs → <em>Custom</em>. Paste the command above and choose "Every minute". Use <em>View output</em> on the job to see each run's summary line or error.</li>
    <li><strong>cPanel:</strong> Advanced → Cron Jobs → "Once per minute" → the same command.</li>
    <li>Use the PHP <strong>CLI</strong> binary, not <code>lsphp</code>. <?php if (count($phpCandidates) > 1): ?>Other CLI binaries found on this server: <?php foreach (array_slice($phpCandidates, 1) as $c): ?><code><?= e($c) ?></code> <?php endforeach ?>.<?php endif ?> The CLI must be PHP <?= e(\App\Core\Requirements::MIN_PHP) ?>+; ideally the same version as the website (<?= e(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?>), e.g. <code>/opt/alt/php<?= PHP_MAJOR_VERSION . PHP_MINOR_VERSION ?>/usr/bin/php</code>.</li>
    <li>Do not add <code>&gt;/dev/null 2&gt;&amp;1</code>: runs print one line, and errors are also kept here and in <code>storage/logs/cron-*.log</code>.</li>
  </ul>
  <p class="text-sm mb-1"><strong>Verify after setting it up:</strong> within a few minutes "Last invocation" above shows the time and the PHP binary cron used. With SSH (or a one-off cron job) you can also run:</p>
  <div class="copy-box"><span class="break"><?= e($cmd) ?> --check</span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($cmd) ?> --check" aria-label="Copy check command"><?= icon('copy') ?></button></div>
  <?php if ($httpKey): ?><p class="hint">URL trigger (only if your host cannot run PHP from cron): <code>/tasks/run/&lt;CRON_KEY from .env&gt;</code>.</p><?php endif ?>
</div></div>

<div class="card mb-2"><div class="table-wrap"><table class="table table-cards"><thead><tr><th>Task</th><th>Schedule</th><th>Last run</th><th>Result</th><th>Last success</th><th class="num">Duration</th><th></th></tr></thead><tbody>
<?php foreach ($tasks as $t => $c): ?><tr><td class="cell-main"><span class="cell-title mono"><?= e($t) ?></span><div class="cell-sub"><?= e($c['label']) ?></div><?php if ($c['last_failure'] && ($c['last']['status'] ?? '') === 'failed'): ?><div class="cell-sub text-danger break"><?= e(str_limit((string) $c['last_failure']['output'], 160)) ?></div><?php endif ?></td><td data-label="Schedule" class="mono text-sm nowrap"><?= e($c['schedule']) ?></td>
  <td data-label="Last run" class="text-sm nowrap"><?= $c['last'] ? e(fmt_date($c['last']['started_at'])) : 'Never' ?></td><td data-label="Result"><?= $c['stale'] ? '<span class="badge badge-danger">Stale</span>' : status_badge($c['last']['status']) ?></td><td data-label="Last success" class="text-sm nowrap"><?= $c['last_success'] ? e(fmt_date($c['last_success'])) : '—' ?></td><td data-label="Duration" class="num nowrap"><?= $c['last'] ? (int) $c['last']['duration_ms'] . ' ms' : '—' ?></td>
  <td class="actions"><form method="post" action="<?= e(admin_url('cron/' . $t . '/run')) ?>"><?= csrf_field() ?><button class="btn btn-soft btn-sm" type="submit">Run now</button></form></td></tr><?php endforeach ?>
</tbody></table></div></div>
<div class="card"><div class="card-header"><h2>Recent runs</h2></div><div class="table-wrap"><table class="table table-cards"><thead><tr><th>Task</th><th>Started</th><th>Status</th><th>Output</th></tr></thead><tbody>
<?php foreach ($runs as $r): ?><tr><td data-label="Task" class="mono"><?= e($r['task']) ?></td><td data-label="Started" class="text-sm nowrap"><?= e(fmt_date($r['started_at'], 'M j H:i:s')) ?></td><td data-label="Status"><?= status_badge($r['status']) ?></td><td class="cell-main mono text-xs break"><?= e(str_limit((string) $r['output'], 240)) ?></td></tr><?php endforeach ?>
<?php if (!$runs): ?><tr><td colspan="4" class="text-center text-muted" style="padding:28px">Cron has never run. Add the cron job above.</td></tr><?php endif ?></tbody></table></div></div>
