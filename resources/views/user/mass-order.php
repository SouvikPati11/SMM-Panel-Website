<?php $this->extend('layouts/user'); ?>
<div class="page-head"><div><h1>Mass order</h1><p>Place many orders at once. Each line is charged and processed independently.</p></div></div>
<div class="grid-main">
  <div class="card"><div class="card-body">
    <form method="post" action="<?= e(url('/mass-order')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="batch_key" value="<?= e($batchKey) ?>">
      <div class="field">
        <label for="mass-orders">Orders <span class="text-muted text-sm">(<span id="mass-count">0</span> / <?= (int) setting('mass_order_max_lines', 100) ?> lines)</span></label>
        <textarea class="textarea code" id="mass-orders" name="orders" rows="12" placeholder="service_id | link | quantity&#10;101 | https://instagram.com/example | 1000&#10;205 | https://tiktok.com/@example/video/1 | 5000" required></textarea>
        <div class="hint">One order per line: <code>service_id | link | quantity</code></div>
      </div>
      <button class="btn btn-primary btn-lg" type="submit"><?= icon('layers') ?> Submit orders</button>
    </form>
  </div></div>
  <div class="card"><div class="card-body text-sm">
    <h3>How it works</h3>
    <ul style="padding-left:18px;color:var(--text-2)">
      <li>Find service IDs on the <a href="<?= e(url('/catalog')) ?>">services page</a>.</li>
      <li>Invalid lines are skipped — valid lines are still placed.</li>
      <li>Only standard services (link + quantity) can be mass-ordered.</li>
    </ul>
  </div></div>
</div>

<?php if ($results): ?>
<div class="card mt-3">
  <div class="card-header"><h2>Results</h2></div>
  <div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>Line</th><th>Result</th><th>Message</th></tr></thead>
    <tbody>
    <?php foreach ($results as $r): ?>
      <tr><td data-label="Line">#<?= (int) $r['line'] ?></td><td data-label="Result"><?= $r['ok'] ? '<span class="badge badge-success">Placed</span>' : '<span class="badge badge-danger">Failed</span>' ?></td>
      <td data-label="Message"><?php if (!empty($r['order_id'])): ?><a href="<?= e(url('/orders/' . $r['order_id'])) ?>"><?= e($r['message']) ?></a><?php else: ?><?= e($r['message']) ?><?php endif ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
</div>
<?php endif ?>
