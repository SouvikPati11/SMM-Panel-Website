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
      <dt>Called by</dt><dd><?= ($cronStatus['trigger'] ?? '') === 'url' ? 'Cron URL' : (!empty($cronStatus['invoked_at']) ? 'PHP command (CLI)' : '—') ?></dd>
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
  <p class="text-sm">Add <strong>one</strong> cron job that runs <strong>every minute</strong> (every 5 minutes also works). Each call starts only the tasks that are due, and database locks stop runs from overlapping. Choose <strong>one</strong> of the two options.</p>

  <h3 class="cron-option-title">Option A — PHP command (recommended)</h3>
  <div class="field"><label for="cron-cmd">Command</label>
    <div class="copy-box"><code id="cron-cmd" class="break-all"><?= e($cmd) ?></code><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($cmd) ?>" aria-label="Copy command"><?= icon('copy') ?> <span class="copy-label">Copy</span></button></div></div>
  <ul class="text-sm">
    <li><strong>Hostinger:</strong> hPanel → Advanced → Cron Jobs → <em>Custom</em>. Paste the command and choose "Every minute". <em>View output</em> on the job shows each run's summary line or error.</li>
    <li><strong>cPanel:</strong> Advanced → Cron Jobs → "Once per minute" → the same command.</li>
    <li>Use the PHP <strong>CLI</strong> binary, not <code>lsphp</code>. <?php if (count($phpCandidates) > 1): ?>Other CLI binaries found on this server: <?php foreach (array_slice($phpCandidates, 1) as $c): ?><code><?= e($c) ?></code> <?php endforeach ?>.<?php endif ?> It must be PHP <?= e(\App\Core\Requirements::MIN_PHP) ?>+, ideally <?= e(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?> like the website (e.g. <code>/opt/alt/php<?= PHP_MAJOR_VERSION . PHP_MINOR_VERSION ?>/usr/bin/php</code>).</li>
    <li>Check it with SSH or a one-off cron job: <code class="break-all"><?= e($cmd) ?> --check</code></li>
  </ul>

  <h3 class="cron-option-title" id="cron-url">Option B — Cron URL</h3>
  <?php if ($cronUrl): ?>
    <p class="text-sm">Use this when your host can only call a URL, or the PHP command does not work. In Hostinger's <em>Custom</em> cron job, paste the <strong>wget</strong> command (a bare URL is not a command and never runs).</p>
    <div class="field"><label for="cron-wget">Hostinger / cPanel command (wget)</label>
      <div class="copy-box"><code id="cron-wget" class="break-all"><?= e($cronUrlCommands['wget']) ?></code><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($cronUrlCommands['wget']) ?>" aria-label="Copy wget command"><?= icon('copy') ?> <span class="copy-label">Copy</span></button></div></div>
    <div class="field"><label for="cron-curl">Alternative (curl)</label>
      <div class="copy-box"><code id="cron-curl" class="break-all"><?= e($cronUrlCommands['curl']) ?></code><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($cronUrlCommands['curl']) ?>" aria-label="Copy curl command"><?= icon('copy') ?> <span class="copy-label">Copy</span></button></div></div>
    <div class="field"><label for="cron-url-value">URL only (for external cron services)</label>
      <div class="copy-box"><code id="cron-url-value" class="break-all"><?= e($cronUrl) ?></code><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($cronUrl) ?>" aria-label="Copy cron URL"><?= icon('copy') ?> <span class="copy-label">Copy</span></button></div>
      <div class="hint">Keep it secret: anyone with the URL can start the tasks (they cannot see or change data). Opening it in a browser runs the due tasks now and shows a JSON summary.</div></div>
    <dl class="dl cron-url-diag">
      <dt>Last URL call</dt><dd><?= !empty($cronStatus['url_last_at']) ? e(fmt_date($cronStatus['url_last_at'], 'M j, Y H:i:s')) . ' <span class="text-muted">(' . e(time_ago($cronStatus['url_last_at'])) . ')</span> · HTTP ' . e((string) ($cronStatus['url_last_status'] ?? '…')) . ' · ' . e((string) ($cronStatus['url_last_ip'] ?? '')) : 'Never' ?></dd>
      <?php if (!empty($cronStatus['url_rejected_at'])): ?><dt>Last rejected call</dt><dd><?= e(fmt_date($cronStatus['url_rejected_at'], 'M j, Y H:i:s')) ?> · wrong or old key from <?= e((string) ($cronStatus['url_rejected_ip'] ?? '?')) ?></dd><?php endif ?>
      <dt>Key source</dt><dd><?= $cronUrlSource === 'env' ? '<code>CRON_KEY</code> in .env' : 'Generated here (stored encrypted)' ?></dd>
    </dl>
    <div class="btn-group mt-1">
      <a class="btn btn-secondary btn-sm" href="<?= e($cronUrl) ?>" target="_blank" rel="noopener noreferrer"><?= icon('external') ?> Run the URL now</a>
      <?php if ($cronUrlSource !== 'env'): ?><form method="post" action="<?= e(admin_url('cron/url')) ?>" data-confirm="Generate a new cron URL? The current URL stops working immediately and the cron job must be updated."><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= icon('refresh') ?> Generate a new URL</button></form><?php endif ?>
    </div>
  <?php else: ?>
    <p class="text-sm">The URL trigger is off. Turn it on if your host can only call a URL.</p>
    <form method="post" action="<?= e(admin_url('cron/url')) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit"><?= icon('link') ?> Create cron URL</button></form>
  <?php endif ?>
  <p class="hint mt-2 mb-0">Within a few minutes of setting up either option, "Last invocation" above shows the time and how cron was called. Runs are also logged in <code>storage/logs/cron-*.log</code>.</p>
</div></div>

<div class="card mb-2"><div class="table-wrap"><table class="table table-cards"><thead><tr><th>Task</th><th>Schedule</th><th>Last run</th><th>Result</th><th>Last success</th><th class="num">Duration</th><th></th></tr></thead><tbody>
<?php foreach ($tasks as $t => $c): ?><tr><td class="cell-main"><span class="cell-title mono"><?= e($t) ?></span><div class="cell-sub"><?= e($c['label']) ?></div><?php if ($c['last_failure'] && ($c['last']['status'] ?? '') === 'failed'): ?><div class="cell-sub text-danger break"><?= e(str_limit((string) $c['last_failure']['output'], 160)) ?></div><?php endif ?></td><td data-label="Schedule" class="mono text-sm nowrap"><?= e($c['schedule']) ?></td>
  <td data-label="Last run" class="text-sm nowrap"><?= $c['last'] ? e(fmt_date($c['last']['started_at'])) : 'Never' ?></td><td data-label="Result"><?= $c['stale'] ? '<span class="badge badge-danger">Stale</span>' : status_badge($c['last']['status']) ?></td><td data-label="Last success" class="text-sm nowrap"><?= $c['last_success'] ? e(fmt_date($c['last_success'])) : '—' ?></td><td data-label="Duration" class="num nowrap"><?= $c['last'] ? (int) $c['last']['duration_ms'] . ' ms' : '—' ?></td>
  <td class="actions"><form method="post" action="<?= e(admin_url('cron/' . $t . '/run')) ?>"><?= csrf_field() ?><button class="btn btn-soft btn-sm" type="submit">Run now</button></form></td></tr><?php endforeach ?>
</tbody></table></div></div>
<div class="card"><div class="card-header"><h2>Recent runs</h2></div><div class="table-wrap"><table class="table table-cards"><thead><tr><th>Task</th><th>Started</th><th>Status</th><th>Output</th></tr></thead><tbody>
<?php foreach ($runs as $r): ?><tr><td data-label="Task" class="mono"><?= e($r['task']) ?></td><td data-label="Started" class="text-sm nowrap"><?= e(fmt_date($r['started_at'], 'M j H:i:s')) ?></td><td data-label="Status"><?= status_badge($r['status']) ?></td><td class="cell-main mono text-xs break"><?= e(str_limit((string) $r['output'], 240)) ?></td></tr><?php endforeach ?>
<?php if (!$runs): ?><tr><td colspan="4" class="text-center text-muted" style="padding:28px">Cron has never run. Add the cron job above.</td></tr><?php endif ?></tbody></table></div></div>
