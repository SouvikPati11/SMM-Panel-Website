<?php $this->extend('layouts/user');
use App\Helpers\Platforms;
// Platforms that actually have services in this catalog, in catalog order.
$platforms = [];
foreach ($categories as $c) {
    $platforms[$c['p']] = true;
}
$platforms = array_keys($platforms);
$icons = [];
foreach (array_merge($platforms, [Platforms::OTHER]) as $p) {
    $icons[$p] = Platforms::icon($p);
} ?>
<div class="page-head">
  <div><h1>New order</h1><p>Choose a service, paste your link and confirm the price.</p></div>
  <?php if (setting('mass_order_enabled', '1') === '1'): ?><a class="btn btn-secondary" href="<?= e(url('/mass-order')) ?>"><?= icon('layers') ?> Mass order</a><?php endif ?>
</div>

<script type="application/json" id="services-data"><?= json_encode(['categories' => $categories, 'services' => $services, 'intervals' => $intervals], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php if (!$services): ?>
  <div class="card"><div class="empty"><?= icon('layers') ?><h3>No services available yet</h3><p>Please check back soon.</p></div></div>
<?php else: ?>
<?php if (count($platforms) > 1): ?>
<div class="platform-bar" role="group" aria-label="Filter services by platform">
  <button class="platform-chip active" type="button" data-platform=""><?= icon('layers', 'platform-icon') ?><span>All</span></button>
  <?php foreach ($platforms as $p): ?><button class="platform-chip" type="button" data-platform="<?= e($p) ?>"><?= $icons[$p] ?><span><?= e(Platforms::label($p)) ?></span></button><?php endforeach ?>
</div>
<?php endif ?>
<div class="grid-main-wide">
  <div class="card"><div class="card-body">
    <form id="order-form" method="post" action="<?= e(url('/order')) ?>" data-info-url="<?= e(url('/order/service/__ID__')) ?>" data-quote-url="<?= e(url('/order/quote')) ?>" data-balance="<?= e($user['balance']) ?>" data-preselect="<?= e((string) $preselect) ?>" data-no-lock>
      <?= csrf_field() ?>
      <input type="hidden" name="form_key" value="<?= e($formKey) ?>">

      <div class="field" style="position:relative">
        <label for="service-search">Search services</label>
        <div class="input-icon"><span class="input-icon-glyph"><?= icon('search') ?></span><input class="input" id="service-search" type="search" placeholder="Type a name or service ID…" autocomplete="off"></div>
        <div class="search-results" id="search-results" hidden></div>
      </div>

      <div class="field">
        <label for="category">Category</label>
        <div class="select-icon"><span class="select-icon-glyph" id="category-icon" aria-hidden="true"><?= $icons[$categories[0]['p']] ?? '' ?></span>
          <select class="select" id="category" name="category">
            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" data-platform="<?= e($c['p']) ?>"><?= e($c['n']) ?></option><?php endforeach ?>
          </select></div>
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
          <span class="text-sm text-muted nowrap"><?= icon('clock') ?> Avg. time: <span id="svc-time">—</span></span>
        </div>
        <div class="field"><div class="label">Description</div><div class="svc-desc" id="svc-desc" aria-live="polite"></div></div>
      </div>

      <div class="field" id="field-order-type" hidden>
        <div class="label">Order type</div>
        <div class="segmented" role="radiogroup" aria-label="Order type">
          <label><input type="radio" name="order_type" value="single" checked> <span><?= icon('check') ?> One-time</span></label>
          <label><input type="radio" name="order_type" value="subscription"> <span><?= icon('refresh') ?> Auto-subscription</span></label>
        </div>
      </div>

      <div class="field">
        <label for="link" id="link-label">Link</label>
        <input class="input" id="link" name="link" required maxlength="1000" autocomplete="off" inputmode="url" value="<?= e(old('link')) ?>">
      </div>

      <div class="field" id="field-quantity">
        <label for="quantity" id="quantity-label">Quantity</label>
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

      <div id="field-subscription" hidden>
        <div class="form-grid">
          <div class="field"><label for="sub_interval">Repeat</label><select class="select" id="sub_interval" name="sub_interval"><?php foreach ($intervals as $h => $label): ?><option value="<?= (int) $h ?>"<?= $h === 24 ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></div>
          <div class="field"><label for="sub_cycles">Number of deliveries</label><input class="input" id="sub_cycles" name="sub_cycles" type="number" inputmode="numeric" min="2" max="<?= (int) $maxCycles ?>" value="7"></div>
        </div>
        <p class="hint" style="margin-top:-6px">The same order is placed automatically on each schedule. The first delivery is charged now; each later one is charged from your balance when it is placed. Pause or cancel at any time.</p>
      </div>

      <div class="charge-box" aria-live="polite">
        <div><div class="text-sm text-muted" id="charge-label">Estimated charge</div><div class="amount"><span id="charge-amount"><?= e(money('0')) ?></span><span class="spinner" id="charge-spinner" hidden aria-label="Updating price"></span></div><div class="text-xs text-muted" id="charge-note"></div></div>
        <div class="text-right text-sm"><div class="text-muted">Your balance</div><strong><?= e(money($user['balance'])) ?></strong></div>
      </div>
      <div class="alert alert-warning" id="charge-warning" hidden><?= icon('alert') ?><div>This order costs more than your balance. <a href="<?= e(url('/funds')) ?>">Add funds</a> first.</div></div>
      <div class="alert alert-danger" id="quote-error" hidden role="alert"></div>

      <button class="btn btn-primary btn-lg btn-block" type="submit" id="order-submit" disabled><?= icon('check') ?> <span id="order-submit-label">Review order</span></button>
      <p class="hint text-center">You will see the final server-calculated price before anything is charged.</p>
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

<dialog class="modal modal-order" id="order-confirm" aria-labelledby="order-confirm-title">
  <div class="modal-head"><h3 id="order-confirm-title">Confirm your order</h3><button class="btn btn-ghost btn-icon btn-sm" type="button" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-body">
    <div id="confirm-review">
      <dl class="dl dl-confirm" id="confirm-list"></dl>
      <ul class="confirm-notes text-sm" id="confirm-notes"></ul>
      <div class="alert alert-warning" id="confirm-insufficient" hidden><?= icon('alert') ?><div>Your balance is too low for this order. <a href="<?= e(url('/funds')) ?>">Add funds</a></div></div>
      <div class="alert alert-danger" id="confirm-error" hidden role="alert"></div>
      <div class="modal-actions">
        <button class="btn btn-secondary" type="button" data-close-dialog>Cancel</button>
        <button class="btn btn-primary" type="button" id="confirm-submit"><?= icon('check') ?> <span>Confirm order</span></button>
      </div>
    </div>
    <div id="confirm-done" class="text-center" hidden>
      <div class="done-icon"><?= icon('check-circle') ?></div>
      <h3 id="done-title">Order placed</h3>
      <p class="text-muted" id="done-text"></p>
      <div class="modal-actions modal-actions-center">
        <a class="btn btn-secondary" id="done-view" href="#">View details</a>
        <button class="btn btn-primary" type="button" id="done-new"><?= icon('plus') ?> New order</button>
      </div>
    </div>
  </div>
</dialog>
<script type="application/json" id="platform-icons"><?= json_encode($icons, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif ?>
