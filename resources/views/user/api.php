<?php $this->extend('layouts/user'); ?>
<div class="page-head"><div><h1>API access</h1><p>Connect your own panel or scripts using the standard SMM API v2 format.</p></div><a class="btn btn-secondary" href="<?= e(url('/api-docs')) ?>"><?= icon('book') ?> API documentation</a></div>
<div class="grid-main">
  <div>
    <?php if ($newKey): ?>
    <div class="alert alert-warning"><?= icon('alert') ?><div><div class="alert-title">Copy your API key now</div>For your security we only store a hash of it — it will not be shown again.</div></div>
    <div class="copy-box mb-2" style="font-size:15px"><span id="new-key"><?= e($newKey) ?></span><button class="btn btn-primary btn-sm" type="button" data-copy="<?= e($newKey) ?>"><?= icon('copy') ?> <span class="copy-label">Copy</span></button></div>
    <?php endif ?>
    <div class="card"><div class="card-body">
      <dl class="dl">
        <dt>API URL</dt><dd><div class="copy-box"><span><?= e(url('/api/v2')) ?></span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e(url('/api/v2')) ?>"><?= icon('copy') ?></button></div></dd>
        <dt>Your key</dt><dd><?php if ($key): ?><code><?= e($key['key_prefix']) ?>••••••••••••••••••••••••••••••••</code> <span class="text-muted text-sm">created <?= e(fmt_date($key['created_at'], 'M j, Y')) ?></span><?php else: ?><span class="text-muted">No active key</span><?php endif ?></dd>
        <?php if ($key): ?><dt>Last used</dt><dd><?= $key['last_used_at'] ? e(fmt_date($key['last_used_at'])) . ' from ' . e($key['last_used_ip']) : 'Never' ?></dd><?php endif ?>
        <dt>Rate limit</dt><dd><?= (int) setting('api_rate_limit', 60) ?> requests / <?= (int) setting('api_rate_window', 60) ?>s</dd>
      </dl>
      <div class="btn-group mt-2">
        <form method="post" action="<?= e(url('/account/api/generate')) ?>" <?= $key ? 'data-confirm="Generating a new key immediately disables the current one. Continue?"' : '' ?>><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= icon('refresh') ?> <?= $key ? 'Regenerate key' : 'Generate API key' ?></button></form>
        <?php if ($key): ?><form method="post" action="<?= e(url('/account/api/revoke')) ?>" data-confirm="Revoke your API key? Integrations using it will stop working."><?= csrf_field() ?><button class="btn btn-ghost" type="submit">Revoke</button></form><?php endif ?>
      </div>
      <?php if ((int) $user['api_enabled'] !== 1): ?><div class="alert alert-danger mt-2 mb-0"><?= icon('lock') ?><div>API access is disabled for your account. Contact support.</div></div><?php endif ?>
    </div></div>
  </div>
  <div class="card"><div class="card-header"><h2>Recent API requests</h2></div>
    <?php if (!$logs): ?><div class="empty" style="padding:24px">No requests yet.</div><?php else: ?>
    <ul class="list-plain list-rows"><?php foreach ($logs as $l): ?>
      <li><div><div class="cell-title mono"><?= e($l['action'] ?: '—') ?></div><div class="cell-sub"><?= e($l['ip']) ?> · <?= e(time_ago($l['created_at'])) ?></div></div><span class="badge <?= (int) $l['http_status'] < 400 ? 'badge-success' : 'badge-danger' ?> no-dot"><?= (int) $l['http_status'] ?></span></li>
    <?php endforeach ?></ul>
    <?php endif ?>
  </div>
</div>
