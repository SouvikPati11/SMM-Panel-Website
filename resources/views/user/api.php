<?php $this->extend('layouts/user');
$endpoint = url('/api/v2');
$userEnabled = (int) $user['api_enabled'] === 1;
$state = !$userEnabled ? ['Disabled', 'badge-danger', 'API access is disabled for your account. Contact support.'] : ($key ? ['Active', 'badge-success', 'Your key is active and can place orders from your balance.'] : ['No key yet', 'badge-muted', 'Generate a key to start using the API.']);
$actions = [
    ['services', 'List services with your price per 1000, min/max and features.', '—'],
    ['add', 'Place an order (charged from your balance).', 'service, link, quantity (+ runs, interval, comments… by service type)'],
    ['status', 'Status of one order, or up to 100 orders at once.', 'order — or orders=1,2,3'],
    ['refill', 'Request a refill for a completed order (refill services).', 'order'],
    ['refill_status', 'Status of a refill request.', 'refill'],
    ['cancel', 'Cancel orders that allow it.', 'orders=1,2,3'],
    ['balance', 'Your current balance and currency.', '—'],
];
$curl = "curl -X POST " . $endpoint . " \\\n  -d key=YOUR_API_KEY \\\n  -d action=balance";
$curlAdd = "curl -X POST " . $endpoint . " \\\n  -d key=YOUR_API_KEY \\\n  -d action=add \\\n  -d service=1 \\\n  -d link=https://instagram.com/yourpage \\\n  -d quantity=1000";
$php = "<?php\n\$ch = curl_init('" . $endpoint . "');\ncurl_setopt_array(\$ch, [\n    CURLOPT_POST => true,\n    CURLOPT_RETURNTRANSFER => true,\n    CURLOPT_POSTFIELDS => http_build_query([\n        'key' => getenv('SMM_API_KEY'), // keep the key out of your code\n        'action' => 'status',\n        'order' => 123,\n    ]),\n]);\n\$result = json_decode(curl_exec(\$ch), true);"; ?>
<div class="page-head"><div><h1>API access</h1><p>Connect your own panel, bot or scripts with the standard SMM API v2.</p></div><a class="btn btn-secondary" href="<?= e(url('/api-docs')) ?>"><?= icon('book') ?> Full documentation</a></div>

<?php if ($newKey): ?>
<div class="card api-newkey mb-2"><div class="card-body">
  <div class="alert alert-warning mb-2"><?= icon('alert') ?><div><div class="alert-title">Copy your API key now</div>For your security only a hash is stored. It will not be shown again; if you lose it, generate a new one.</div></div>
  <div class="copy-box copy-box-lg"><code class="break-words" id="new-key"><?= e($newKey) ?></code><button class="btn btn-primary btn-sm" type="button" data-copy="<?= e($newKey) ?>"><?= icon('copy') ?> <span class="copy-label">Copy key</span></button></div>
</div></div>
<?php endif ?>

<div class="grid-main">
  <div>
    <div class="card mb-2"><div class="card-header"><h2>Your API</h2><span class="badge <?= $state[1] ?>"><?= e($state[0]) ?></span></div><div class="card-body">
      <p class="text-sm text-muted mt-0"><?= e($state[2]) ?></p>
      <div class="api-grid">
        <div class="api-item"><div class="label">API endpoint</div><div class="copy-box"><code class="break-words"><?= e($endpoint) ?></code><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($endpoint) ?>" aria-label="Copy API endpoint"><?= icon('copy') ?><span class="copy-label sr-only">Copy</span></button></div></div>
        <div class="api-item"><div class="label">API key</div>
          <?php if ($key): ?><div class="copy-box"><code><?= e($key['key_prefix']) ?>••••••••••••••••</code><span class="text-xs text-muted nowrap">hidden</span></div><div class="hint">Created <?= e(fmt_date($key['created_at'], 'M j, Y')) ?> · last used <?= $key['last_used_at'] ? e(time_ago($key['last_used_at'])) . ' from ' . e($key['last_used_ip']) : 'never' ?></div>
          <?php else: ?><div class="copy-box"><span class="text-muted">No active key</span></div><?php endif ?></div>
        <div class="api-item"><div class="label">Method &amp; format</div><div class="api-value"><code>POST</code> form fields · JSON responses</div></div>
        <div class="api-item"><div class="label">Rate limit</div><div class="api-value"><?= (int) setting('api_rate_limit', 60) ?> requests per <?= (int) setting('api_rate_window', 60) ?> seconds</div></div>
      </div>
      <?php if ($userEnabled): ?>
      <div class="btn-group mt-2">
        <form method="post" action="<?= e(url('/account/api/generate')) ?>" <?= $key ? 'data-confirm="Generating a new key immediately disables the current one. Continue?"' : '' ?>><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= icon('key') ?> <?= $key ? 'Regenerate key' : 'Generate API key' ?></button></form>
        <?php if ($key): ?><form method="post" action="<?= e(url('/account/api/revoke')) ?>" data-confirm="Revoke your API key? Integrations using it stop working immediately."><?= csrf_field() ?><button class="btn btn-ghost text-danger" type="submit">Revoke</button></form><?php endif ?>
      </div>
      <?php else: ?><div class="alert alert-danger mt-2 mb-0"><?= icon('lock') ?><div>API access is disabled for your account. Contact support.</div></div><?php endif ?>
    </div></div>

    <div class="card mb-2"><div class="card-header"><h2>Quick start</h2></div><div class="card-body">
      <ol class="api-steps">
        <li>Generate a key above and store it somewhere safe (an environment variable, not your code).</li>
        <li>Send a <code>POST</code> request to the endpoint with <code>key</code>, <code>action</code> and the action's parameters.</li>
        <li>Every response is JSON. Errors look like <code>{"error": "…"}</code> with an HTTP 4xx status.</li>
      </ol>
      <div class="tabs tabs-inline api-tabs" role="tablist">
        <button type="button" role="tab" class="active" aria-selected="true" data-api-tab="ex-balance">Check balance</button>
        <button type="button" role="tab" aria-selected="false" data-api-tab="ex-add">Place an order</button>
        <button type="button" role="tab" aria-selected="false" data-api-tab="ex-php">PHP</button>
      </div>
      <div class="api-example" id="ex-balance" role="tabpanel">
        <div class="code-head"><span>Request</span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($curl) ?>"><?= icon('copy') ?> <span class="copy-label">Copy</span></button></div>
        <pre><code><?= e($curl) ?></code></pre>
        <div class="code-head"><span>Response</span></div>
        <pre><code>{"balance": "<?= e(\App\Core\Money::of((string) $user['balance'], 5)) ?>", "currency": "<?= e(setting('currency_code', 'USD')) ?>"}</code></pre>
      </div>
      <div class="api-example" id="ex-add" role="tabpanel" hidden>
        <div class="code-head"><span>Request</span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($curlAdd) ?>"><?= icon('copy') ?> <span class="copy-label">Copy</span></button></div>
        <pre><code><?= e($curlAdd) ?></code></pre>
        <div class="code-head"><span>Response</span></div>
        <pre><code>{"order": 23501}</code></pre>
      </div>
      <div class="api-example" id="ex-php" role="tabpanel" hidden>
        <div class="code-head"><span>Order status in PHP</span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($php) ?>"><?= icon('copy') ?> <span class="copy-label">Copy</span></button></div>
        <pre><code><?= e($php) ?></code></pre>
        <div class="code-head"><span>Response</span></div>
        <pre><code>{"charge": "2.50", "start_count": "3572", "status": "In progress", "remains": "157", "currency": "<?= e(setting('currency_code', 'USD')) ?>"}</code></pre>
      </div>
    </div></div>

    <div class="card mb-2"><div class="card-header"><h2>Available actions</h2></div>
      <div class="table-wrap"><table class="table table-cards"><thead><tr><th>Action</th><th>What it does</th><th>Parameters</th></tr></thead><tbody>
        <?php foreach ($actions as [$a, $what, $params]): ?><tr><td class="cell-main"><code><?= e($a) ?></code></td><td data-label="What it does" class="text-sm"><?= e($what) ?></td><td data-label="Parameters" class="text-sm"><?= e($params) ?></td></tr><?php endforeach ?>
      </tbody></table></div>
    </div>
  </div>

  <div>
    <div class="card mb-2 api-warning"><div class="card-body">
      <h3 class="mt-0"><?= icon('shield') ?> Keep your key secret</h3>
      <ul class="text-sm mb-0">
        <li>Anyone with your key can place orders <strong>from your balance</strong>.</li>
        <li>Never put it in a URL, a public repository, a browser script or a screenshot.</li>
        <li>Send it only in the POST body over HTTPS.</li>
        <li>If it may have leaked, <strong>regenerate</strong> it: the old key stops working at once.</li>
        <li>We never ask for your key by email or chat.</li>
      </ul>
    </div></div>
    <div class="card"><div class="card-header"><h2>Recent API requests</h2></div>
      <?php if (!$logs): ?><div class="empty" style="padding:24px"><p class="mb-0">No requests yet.</p></div><?php else: ?>
      <ul class="list-plain list-rows"><?php foreach ($logs as $l): ?>
        <li><div class="min-w-0"><div class="cell-title mono"><?= e($l['action'] ?: '—') ?></div><div class="cell-sub truncate"><?= e($l['ip']) ?> · <?= e(time_ago($l['created_at'])) ?></div></div><span class="badge <?= (int) $l['http_status'] < 400 ? 'badge-success' : 'badge-danger' ?> no-dot"><?= (int) $l['http_status'] ?></span></li>
      <?php endforeach ?></ul>
      <?php endif ?>
    </div>
  </div>
</div>
