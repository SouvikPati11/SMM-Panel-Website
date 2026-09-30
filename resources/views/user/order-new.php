<?php $this->extend('layouts/user');
use App\Helpers\Platforms;
// Shortcut cards: All + the main platforms + Other (everything else). Counts come from the
// categories' configured platform (Admin → Categories), never from service IDs.
$groupCount = [];
$perCat = array_count_values(array_column($services, 'c'));
foreach ($categories as $c) {
    $groupCount[$c['g']] = ($groupCount[$c['g']] ?? 0) + (int) ($perCat[(int) $c['id']] ?? 0);
}
$cards = array_merge(Platforms::SHORTCUTS, [Platforms::OTHER]);
$icons = [];
foreach ($categories as $c) {
    $icons[$c['p']] ??= Platforms::icon($c['p']);
}
foreach ($cards as $p) {
    $icons[$p] ??= Platforms::icon($p);
} ?>
<div class="page-head">
  <div><h1>New order</h1><p>Choose a service, paste your link and confirm the price.</p></div>
  <?php if (setting('mass_order_enabled', '1') === '1'): ?><a class="btn btn-secondary" href="<?= e(url('/mass-order')) ?>"><?= icon('layers') ?> Mass order</a><?php endif ?>
</div>

<script type="application/json" id="services-data"><?= json_encode(['categories' => $categories, 'services' => $services, 'intervals' => $intervals], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php if (!$services): ?>
  <div class="card"><div class="empty"><?= icon('layers') ?><h3>No services available yet</h3><p>Please check back soon.</p></div></div>
<?php else: ?>
<div class="order-layout">
  <section class="card pf-panel" aria-labelledby="platform-title">
    <div class="pf-panel-head">
      <h2 id="platform-title" class="pf-panel-title">Platforms</h2>
      <div class="input-icon pf-search"><span class="input-icon-glyph"><?= icon('search') ?></span><input class="input input-sm" id="platform-search" type="search" placeholder="Search platforms" aria-label="Search platforms" autocomplete="off" aria-controls="pf-grid"></div>
    </div>
    <div class="pf-grid" id="pf-grid" role="group" aria-label="Filter categories and services by platform">
      <button class="pf-card active" type="button" data-platform="" data-name="all" aria-pressed="true"><span class="pf-card-icon"><?= icon('layers', 'platform-icon') ?></span><span class="pf-card-text"><span class="pf-card-name">All</span><span class="pf-card-meta"><?= count($services) ?> services</span></span></button>
      <?php foreach ($cards as $p): $n = $groupCount[$p] ?? 0; ?><button class="pf-card pf-card-<?= e($p) ?>" type="button" data-platform="<?= e($p) ?>" data-name="<?= e(mb_strtolower(Platforms::label($p) . ' ' . $p . ($p === 'x' ? ' twitter' : '') . ($p === 'vk' ? ' vkontakte' : ''))) ?>" aria-pressed="false"<?= $n ? '' : ' disabled' ?>><span class="pf-card-icon"><?= $icons[$p] ?></span><span class="pf-card-text"><span class="pf-card-name"><?= e(Platforms::label($p)) ?></span><span class="pf-card-meta"><?= $n ? $n . ' ' . ($n === 1 ? 'service' : 'services') : 'No services' ?></span></span></button><?php endforeach ?>
      <p class="pf-empty text-sm text-muted" id="pf-empty" hidden>No platform matches.</p>
    </div>
  </section>

  <div class="card order-main"><div class="card-body">
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
            <?php foreach ($categories as $c): $n = (int) ($perCat[(int) $c['id']] ?? 0); ?><option value="<?= (int) $c['id'] ?>" data-platform="<?= e($c['p']) ?>" data-group="<?= e($c['g']) ?>" data-sub="<?= e(Platforms::label($c['p'])) ?>" data-meta="<?= $n ?> <?= $n === 1 ? 'service' : 'services' ?>"><?= e($c['n']) ?></option><?php endforeach ?>
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

      <div class="field" id="field-link">
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
          <div class="field"><label for="sub_cycles">Number of deliveries</label><input class="input" id="sub_cycles" name="sub_cycles" type="number" inputmode="numeric" min="2" max="<?= (int) $maxCycles ?>" value="7"><div class="hint" id="sub-cycles-hint"></div></div>
        </div>
        <p class="hint" style="margin-top:-6px">The same order is placed automatically on each schedule. The first delivery is charged now; each later one is charged from your balance when it is placed. Pause or cancel at any time.</p>
      </div>

      <fieldset class="sub-posts" id="field-subscription-posts" hidden disabled>
        <legend class="sub-posts-title"><?= icon('refresh') ?> Subscription details</legend>
        <div class="field">
          <label for="sub_username">Username</label>
          <div class="input-icon"><span class="input-icon-glyph"><?= icon('user') ?></span><input class="input" id="sub_username" name="sub_username" maxlength="200" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="username or profile link" required></div>
          <div class="hint">New posts on this account are delivered to automatically.</div>
        </div>
        <div class="form-grid">
          <div class="field"><label for="sub_posts">New posts</label><input class="input" id="sub_posts" name="sub_posts" type="number" inputmode="numeric" min="1" step="1" value="1" required><div class="hint" id="sub-posts-hint"></div></div>
          <div class="field" id="field-old-posts"><label for="sub_old_posts">Old posts</label><input class="input" id="sub_old_posts" name="sub_old_posts" type="number" inputmode="numeric" min="0" step="1" value="0"><div class="hint" id="sub-old-hint"></div></div>
        </div>
        <div class="field">
          <div class="label" id="sub-qty-label">Quantity per post</div>
          <div class="minmax" role="group" aria-labelledby="sub-qty-label">
            <div><label class="minmax-label" for="sub_min">Min</label><input class="input" id="sub_min" name="sub_min" type="number" inputmode="numeric" min="1" step="1" required></div>
            <span class="minmax-sep" aria-hidden="true">–</span>
            <div><label class="minmax-label" for="sub_max">Max</label><input class="input" id="sub_max" name="sub_max" type="number" inputmode="numeric" min="1" step="1" required></div>
          </div>
          <div class="hint" id="sub-qty-hint"></div>
        </div>
        <div class="form-grid">
          <div class="field"><label for="sub_delay">Delay</label><select class="select" id="sub_delay" name="sub_delay"><?php foreach (\App\Services\SubscriptionService::DELAYS as $m => $label): ?><option value="<?= (int) $m ?>"><?= e($label) ?></option><?php endforeach ?></select><div class="hint">Wait before each new post is delivered.</div></div>
          <div class="field"><label for="sub_expiry">Expiry</label><input class="input" id="sub_expiry" name="sub_expiry" type="date"><div class="hint" id="sub-expiry-hint">Optional. The subscription stops on this date.</div></div>
        </div>
      </fieldset>

      <div class="charge-box" aria-live="polite">
        <div><div class="text-sm text-muted" id="charge-label">Estimated charge</div><div class="amount"><span id="charge-amount"><?= e(money('0')) ?></span><span class="spinner" id="charge-spinner" hidden aria-label="Updating price"></span></div><div class="text-xs text-muted" id="charge-note"></div></div>
        <div class="text-right text-sm"><div class="text-muted">Your balance</div><strong><?= e(money($user['balance'])) ?></strong></div>
      </div>
      <div class="alert alert-warning" id="charge-warning" hidden><?= icon('alert') ?><div>This order costs more than your balance. <a href="<?= e(url('/funds')) ?>">Add funds</a> first.</div></div>
      <div class="alert alert-danger" id="quote-error" hidden role="alert"></div>

      <button class="btn btn-primary btn-lg btn-block" type="submit" id="order-submit" disabled><?= icon('check') ?> <span id="order-submit-label">Review order</span></button>
      <p class="hint text-center">You will see the final server-calculated price before anything is charged. Need help choosing? <a href="<?= e(url('/tickets/new?category=order')) ?>">Ask support</a> or browse the <a href="<?= e(url('/catalog')) ?>">full service list</a>.</p>
    </form>
  </div></div>

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
