<?php $this->extend('layouts/public');
$endpoint = url('/api/v2');
$sid = (int) $exampleService;
$sections = [
    ['services', 'Service list', ['key' => 'Your API key', 'action' => 'services'], '[
  {
    "service": ' . $sid . ',
    "name": "Instagram Followers",
    "type": "Default",
    "category": "Instagram",
    "rate": "0.90",
    "min": "50",
    "max": "10000",
    "refill": true,
    "cancel": true,
    "dripfeed": false
  }
]'],
    ['add', 'Add order', ['key' => 'Your API key', 'action' => 'add', 'service' => 'Service ID', 'link' => 'Link to page', 'quantity' => 'Needed quantity', 'runs (optional)' => 'Runs to deliver (drip-feed services)', 'interval (optional)' => 'Interval in minutes (drip-feed)', 'comments' => 'Custom comments, one per line (custom comments types)', 'usernames' => 'Usernames, one per line (mentions)', 'username' => 'Username (comment likes)', 'answer_number' => 'Answer number (poll)', 'keywords' => 'Keywords (keywords / search services)'], '{
  "order": 23501
}'],
    ['status', 'Order status', ['key' => 'Your API key', 'action' => 'status', 'order' => 'Order ID'], '{
  "charge": "0.27819",
  "start_count": "3572",
  "status": "Partial",
  "remains": "157",
  "currency": "' . e(setting('currency_code', 'USD')) . '"
}'],
    ['status', 'Multiple orders status', ['key' => 'Your API key', 'action' => 'status', 'orders' => 'Order IDs separated by comma (up to 100)'], '{
  "1": { "charge": "0.27819", "start_count": "3572", "status": "Partial", "remains": "157", "currency": "USD" },
  "10": { "error": "Incorrect order ID" },
  "100": { "charge": "1.44219", "start_count": "234", "status": "In progress", "remains": "10", "currency": "USD" }
}'],
    ['refill', 'Create refill', ['key' => 'Your API key', 'action' => 'refill', 'order' => 'Order ID'], '{
  "refill": "1"
}'],
    ['refill_status', 'Refill status', ['key' => 'Your API key', 'action' => 'refill_status', 'refill' => 'Refill ID'], '{
  "status": "Completed"
}'],
    ['cancel', 'Cancel orders', ['key' => 'Your API key', 'action' => 'cancel', 'orders' => 'Order IDs separated by comma (up to 100)'], '[
  { "order": 9, "cancel": { "error": "Incorrect order ID" } },
  { "order": 2, "cancel": 1 }
]'],
    ['balance', 'User balance', ['key' => 'Your API key', 'action' => 'balance'], '{
  "balance": "100.84292",
  "currency": "' . e(setting('currency_code', 'USD')) . '"
}'],
];
$sections = array_map(static fn ($s) => ['id' => slugify($s[1]), 'action' => $s[0], 'title' => $s[1], 'params' => $s[2], 'response' => $s[3]], $sections);
$php = "<?php\n\$ch = curl_init('" . $endpoint . "');\ncurl_setopt_array(\$ch, [\n    CURLOPT_POST => true,\n    CURLOPT_RETURNTRANSFER => true,\n    CURLOPT_POSTFIELDS => http_build_query([\n        'key'      => 'YOUR_API_KEY',\n        'action'   => 'add',\n        'service'  => " . $sid . ",\n        'link'     => 'https://instagram.com/example',\n        'quantity' => 1000,\n    ]),\n]);\n\$order = json_decode(curl_exec(\$ch), true);\necho \$order['order'] ?? \$order['error'];";
$curl = "curl -X POST " . $endpoint . " \\\n  -d key=YOUR_API_KEY \\\n  -d action=balance";
$python = "import requests\n\nr = requests.post('" . $endpoint . "', data={\n    'key': 'YOUR_API_KEY',\n    'action': 'status',\n    'order': 23501,\n})\nprint(r.json())";
/** Code block with its own horizontal scroll and a copy button. */
$code = static fn (string $label, string $text, string $lang = '') => '<div class="code-block"><div class="code-block-head"><span>' . e($label) . '</span><button class="btn btn-ghost btn-sm code-copy" type="button" data-copy="' . e($text) . '" aria-label="Copy ' . e($label) . '">' . icon('copy') . ' <span class="copy-label">Copy</span></button></div><pre tabindex="0"' . ($lang ? ' data-lang="' . e($lang) . '"' : '') . '><code>' . e($text) . '</code></pre></div>';
?>
<section class="page-hero api-hero">
  <div class="container">
    <ol class="breadcrumb"><li><a href="<?= e(url('/')) ?>">Home</a></li><li>API</li></ol>
    <h1>API documentation</h1>
    <p>Our API follows the widely used SMM panel API v2 format, so existing panel scripts and client libraries work without changes.</p>
  </div>
</section>
<div class="container api-docs">
  <div class="card mb-2 api-overview"><div class="card-body">
    <div class="field mb-0"><div class="label">API URL</div>
      <div class="copy-box copy-box-lg"><code id="api-url" class="break-all"><?= e($endpoint) ?></code><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($endpoint) ?>" aria-label="Copy API URL"><?= icon('copy') ?> <span class="copy-label">Copy</span></button></div>
    </div>
    <dl class="api-facts">
      <div><dt>HTTP method</dt><dd><code>POST</code> <span class="text-muted">(GET also accepted)</span></dd></div>
      <div><dt>Content type</dt><dd><code>application/x-www-form-urlencoded</code> or JSON</dd></div>
      <div><dt>Response</dt><dd>JSON</dd></div>
      <div><dt>Rate limit</dt><dd><?= (int) setting('api_rate_limit', 60) ?> requests / <?= (int) setting('api_rate_window', 60) ?> s per key <span class="text-muted">(HTTP 429)</span></dd></div>
      <div><dt>API key</dt><dd><?php if (auth_user()): ?><a href="<?= e(url('/account/api')) ?>">Generate your key</a><?php else: ?><a href="<?= e(url('/register')) ?>">Create an account</a> to get a key<?php endif ?></dd></div>
    </dl>
  </div></div>

  <nav class="api-nav" aria-label="API methods">
    <?php foreach ($sections as $sec): ?><a class="api-nav-link" href="#<?= e($sec['id']) ?>"><?= e($sec['title']) ?></a><?php endforeach ?>
    <a class="api-nav-link" href="#errors">Errors</a><a class="api-nav-link" href="#examples">Examples</a>
  </nav>

  <div class="api-layout">
    <div class="api-main">
      <?php foreach ($sections as $sec): ?>
      <section class="card mb-2 api-method" id="<?= e($sec['id']) ?>" aria-labelledby="<?= e($sec['id']) ?>-title">
        <div class="card-header api-method-head"><h2 id="<?= e($sec['id']) ?>-title"><?= e($sec['title']) ?></h2><code class="api-action">action=<?= e($sec['action']) ?></code></div>
        <div class="card-body">
          <div class="label">Parameters</div>
          <dl class="api-params">
            <?php foreach ($sec['params'] as $p => $d): ?><div class="api-param"><dt><code><?= e($p) ?></code></dt><dd><?= e($d) ?></dd></div><?php endforeach ?>
          </dl>
          <?= $code('Example response', $sec['response'], 'json') ?>
        </div>
      </section>
      <?php endforeach ?>

      <section class="card mb-2" id="errors" aria-labelledby="errors-title"><div class="card-header"><h2 id="errors-title">Errors</h2></div><div class="card-body">
        <p>Errors are returned as <code>{"error": "message"}</code> with an appropriate HTTP status:</p>
        <ul class="api-errors">
          <li><code>401</code> Invalid or missing API key</li>
          <li><code>400</code> / <code>422</code> Invalid parameters (e.g. <code>Incorrect service ID</code>, quantity out of range, <code>Not enough funds on balance</code>)</li>
          <li><code>429</code> Rate limit exceeded; retry after the window resets</li>
          <li><code>403</code> API access disabled for the account</li>
        </ul>
        <p class="mb-0">Order statuses: <code>Pending</code>, <code>Processing</code>, <code>In progress</code>, <code>Completed</code>, <code>Partial</code>, <code>Canceled</code>. Undelivered quantity of partial and cancelled orders is refunded to your balance automatically.</p>
      </div></section>
    </div>

    <aside class="api-side" id="examples" aria-label="Code examples">
      <div class="card mb-2"><div class="card-header"><h2>Examples</h2></div><div class="card-body api-examples">
        <?= $code('PHP', $php, 'php') ?>
        <?= $code('cURL', $curl, 'bash') ?>
        <?= $code('Python', $python, 'python') ?>
      </div></div>
    </aside>
  </div>
</div>
