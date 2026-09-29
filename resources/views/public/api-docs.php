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
?>
<section class="page-hero">
  <div class="container">
    <ol class="breadcrumb"><li><a href="<?= e(url('/')) ?>">Home</a></li><li>API</li></ol>
    <h1>API documentation</h1>
    <p>Our API follows the widely used SMM panel API v2 format, so existing panel scripts and client libraries work without changes.</p>
  </div>
</section>
<div class="container">
  <div class="grid-main-wide">
    <div>
      <div class="card mb-2"><div class="card-body">
        <dl class="dl">
          <dt>API URL</dt><dd><div class="copy-box"><span id="api-url"><?= e($endpoint) ?></span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($endpoint) ?>"><?= icon('copy') ?></button></div></dd>
          <dt>HTTP method</dt><dd><code>POST</code> (GET is also accepted)</dd>
          <dt>Content type</dt><dd><code>application/x-www-form-urlencoded</code> or JSON</dd>
          <dt>Response format</dt><dd>JSON</dd>
          <dt>API key</dt><dd><?php if (auth_user()): ?><a href="<?= e(url('/account/api')) ?>">Generate your key</a><?php else: ?><a href="<?= e(url('/register')) ?>">Create an account</a> to get an API key<?php endif ?></dd>
          <dt>Rate limit</dt><dd><?= (int) setting('api_rate_limit', 60) ?> requests per <?= (int) setting('api_rate_window', 60) ?> seconds per key (HTTP 429 when exceeded)</dd>
        </dl>
      </div></div>

      <?php foreach ($sections as [$action, $title, $params, $response]): ?>
      <div class="card mb-2" id="<?= e(slugify($title)) ?>">
        <div class="card-header"><h2><?= e($title) ?></h2><code>action=<?= e($action) ?></code></div>
        <div class="table-wrap">
          <table class="table"><thead><tr><th>Parameter</th><th>Description</th></tr></thead><tbody>
            <?php foreach ($params as $p => $d): ?><tr><td class="mono nowrap"><?= e($p) ?></td><td><?= e($d) ?></td></tr><?php endforeach ?>
          </tbody></table>
        </div>
        <div class="card-body"><div class="label">Example response</div><pre><code><?= e($response) ?></code></pre></div>
      </div>
      <?php endforeach ?>

      <div class="card mb-2"><div class="card-header"><h2>Errors</h2></div><div class="card-body">
        <p>Errors are returned as <code>{"error": "message"}</code> with an appropriate HTTP status:</p>
        <ul>
          <li><code>401</code> — invalid or missing API key</li>
          <li><code>400</code> / <code>422</code> — invalid parameters (e.g. <code>Incorrect service ID</code>, quantity out of range, <code>Not enough funds on balance</code>)</li>
          <li><code>429</code> — rate limit exceeded; retry after the window resets</li>
          <li><code>403</code> — API access disabled for the account</li>
        </ul>
        <p class="mb-0">Order statuses: <code>Pending</code>, <code>Processing</code>, <code>In progress</code>, <code>Completed</code>, <code>Partial</code>, <code>Canceled</code>. Undelivered quantity of partial and cancelled orders is refunded to your balance automatically.</p>
      </div></div>
    </div>

    <aside class="sticky-side">
      <div class="card mb-2"><div class="card-header"><h3>PHP example</h3></div><div class="card-body">
<pre><code>&lt;?php
$ch = curl_init('<?= e($endpoint) ?>');
curl_setopt_array($ch, [
    CURLOPT_POST =&gt; true,
    CURLOPT_RETURNTRANSFER =&gt; true,
    CURLOPT_POSTFIELDS =&gt; http_build_query([
        'key'      =&gt; 'YOUR_API_KEY',
        'action'   =&gt; 'add',
        'service'  =&gt; <?= $sid ?>,
        'link'     =&gt; 'https://instagram.com/example',
        'quantity' =&gt; 1000,
    ]),
]);
$order = json_decode(curl_exec($ch), true);
echo $order['order'] ?? $order['error'];</code></pre>
      </div></div>
      <div class="card"><div class="card-header"><h3>cURL example</h3></div><div class="card-body">
<pre><code>curl -X POST <?= e($endpoint) ?> \
  -d key=YOUR_API_KEY \
  -d action=balance</code></pre>
      </div></div>
    </aside>
  </div>
</div>
