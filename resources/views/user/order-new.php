<?php $this->extend('layouts/user'); ?>
<div class="page-head">
  <div><h1>New order</h1><p>Choose a service, paste your link and confirm the price.</p></div>
  <?php if (setting('mass_order_enabled', '1') === '1'): ?><a class="btn btn-secondary" href="<?= e(url('/mass-order')) ?>"><?= icon('layers') ?> Mass order</a><?php endif ?>
</div>

<script type="application/json" id="services-data"><?= json_encode(['categories' => $categories, 'services' => $services], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php if (!$services): ?>
  <div class="card"><div class="empty"><?= icon('layers') ?><h3>No services available yet</h3><p>Please check back soon.</p></div></div>
<?php else: ?>
<div class="grid-main-wide">
  <div class="card"><div class="card-body">
    <form id="order-form" method="post" action="<?= e(url('/order')) ?>" data-info-url="<?= e(url('/order/service/__ID__')) ?>" data-balance="<?= e($user['balance']) ?>" data-preselect="<?= e((string) $preselect) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="form_key" value="<?= e($formKey) ?>">

      <div class="field" style="position:relative">
        <label for="service-search">Search services</label>
        <div style="position:relative"><input class="input" id="service-search" type="search" placeholder="Type a name or service ID…" autocomplete="off" style="padding-left:40px"><span style="position:absolute;left:12px;top:12px;color:var(--muted)"><?= icon('search') ?></span></div>
        <div class="search-results" id="search-results" hidden></div>
      </div>

      <div class="field">
        <label for="category">Category</label>
        <select class="select" id="category" name="category">
          <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['n']) ?></option><?php endforeach ?>
        </select>
      </div>

      <div class="field">
        <label for="service">Service</label>
        <select class="select" id="service" name="service" required></select>
      </div>

      <div id="svc-summary" hidden>
        <div class="svc-summary">
          <div><small>Rate</small><strong id="svc-rate">—</strong></div>
          <div><small>Min</small><strong id="svc-min">—</strong></div>
          <div><small>Max</small><strong id="svc-max">—</strong></div>
        </div>
        <div class="flex justify-between items-center wrap gap-1 mb-1">
          <span class="svc-flags" id="svc-flags"></span>
          <span class="text-sm text-muted"><?= icon('clock') ?> Avg. time: <span id="svc-time">—</span></span>
        </div>
        <div class="field"><div class="label">Description</div><div class="svc-desc" id="svc-desc"></div></div>
      </div>

      <div class="field">
        <label for="link" id="link-label">Link</label>
        <input class="input" id="link" name="link" required maxlength="1000" autocomplete="off" inputmode="url" value="<?= e(old('link')) ?>">
      </div>

      <div class="field" id="field-quantity">
        <label for="quantity">Quantity</label>
        <input class="input" id="quantity" name="quantity" type="number" inputmode="numeric" min="1" step="1" value="<?= e(old('quantity')) ?>">
        <div class="hint" id="qty-hint"></div>
      </div>

      <div class="field" id="field-comments" hidden>
        <label for="comments">Comments <span class="text-muted text-sm">(one per line · <span id="qty-count">0 lines</span>)</span></label>
        <textarea class="textarea" id="comments" name="comments" rows="6"></textarea>
      </div>
      <div class="field" id="field-usernames" hidden>
        <label for="usernames">Usernames <span class="text-muted text-sm">(one per line)</span></label>
        <textarea class="textarea" id="usernames" name="usernames" rows="6"></textarea>
      </div>
      <div class="field" id="field-username" hidden>
        <label for="username">Username of the comment owner</label>
        <input class="input" id="username" name="username" maxlength="500">
      </div>
      <div class="field" id="field-answer" hidden>
        <label for="answer_number">Answer number</label>
        <input class="input" id="answer_number" name="answer_number" inputmode="numeric" maxlength="3">
      </div>
      <div class="field" id="field-keywords" hidden>
        <label for="keywords">Keywords</label>
        <input class="input" id="keywords" name="keywords" maxlength="500" placeholder="keyword one, keyword two">
      </div>

      <div id="field-dripfeed" hidden>
        <div class="field"><label class="switch"><input type="checkbox" id="dripfeed" name="dripfeed" value="1"> <span>Drip-feed (deliver in runs)</span></label></div>
        <div class="form-grid" id="dripfeed-fields" hidden>
          <div class="field"><label for="runs">Runs</label><input class="input" id="runs" name="runs" type="number" min="2" max="1000" value="2"></div>
          <div class="field"><label for="interval">Interval (minutes)</label><input class="input" id="interval" name="interval" type="number" min="1" max="1440" value="30"></div>
        </div>
        <p class="hint" style="margin-top:-6px">Total quantity = quantity × runs.</p>
      </div>

      <div class="charge-box">
        <div><div class="text-sm text-muted">Estimated charge</div><div class="amount" id="charge-amount"><?= e(money('0')) ?></div></div>
        <div class="text-right text-sm"><div class="text-muted">Your balance</div><strong><?= e(money($user['balance'])) ?></strong></div>
      </div>
      <div class="alert alert-warning" id="charge-warning" hidden><?= icon('alert') ?><div>This order costs more than your balance. <a href="<?= e(url('/funds')) ?>">Add funds</a> first.</div></div>

      <button class="btn btn-primary btn-lg btn-block" type="submit" id="order-submit" disabled><?= icon('check') ?> Place order</button>
      <p class="hint text-center">The final price is always calculated on our server.</p>
    </form>
  </div></div>

  <aside class="sticky-side">
    <div class="card mb-2"><div class="card-body">
      <h3>Before you order</h3>
      <ul class="text-sm" style="padding-left:18px;color:var(--text-2)">
        <li>Make sure the account or post is <strong>public</strong>.</li>
        <li>Don't place a second order for the same link until the first completes.</li>
        <li>Don't change the username or delete the post while delivering.</li>
        <li>Undelivered quantity of partial orders is refunded automatically.</li>
      </ul>
    </div></div>
    <div class="card"><div class="card-body text-sm">
      Need help choosing? <a href="<?= e(url('/tickets/new?category=order')) ?>">Ask support</a> or browse the <a href="<?= e(url('/catalog')) ?>">full service list</a>.
    </div></div>
  </aside>
</div>
<?php endif ?>
