<?php $this->extend('layouts/public'); use App\Helpers\Platforms;
$user = auth_user();
$primaryUrl = url($user ? '/order' : '/register'); ?>
<section class="hero hero-smm">
  <div class="container hero-inner">
    <div class="hero-copy">
      <span class="eyebrow"><span class="dot"></span> Automated SMM panel · orders run 24/7</span>
      <h1>Grow every social account from <span class="accent">one panel</span></h1>
      <p class="lead">Followers, likes, views and members for Instagram, TikTok, YouTube, Telegram and more — ordered in seconds, delivered automatically, with partial orders refunded to your balance.</p>
      <div class="hero-cta">
        <a class="btn btn-primary btn-lg" href="<?= e($primaryUrl) ?>"><?= icon('zap') ?> <?= $user ? 'Place an order' : 'Create a free account' ?></a>
        <?php if ($user): ?><a class="btn btn-secondary btn-lg" href="<?= e(url('/dashboard')) ?>">Go to dashboard</a><?php else: ?><a class="btn btn-secondary btn-lg" href="<?= e(url('/login')) ?>">Sign in</a><?php endif ?>
      </div>
      <ul class="hero-trust" aria-label="Highlights">
        <li><?= icon('check-circle') ?> No monthly fees</li>
        <li><?= icon('check-circle') ?> Automatic refunds</li>
        <li><?= icon('check-circle') ?> Reseller API</li>
      </ul>
    </div>
    <div class="hero-visual">
      <img src="<?= e(asset('img/hero-growth.svg')) ?>" width="480" height="420" alt="Illustration of a social media profile on a phone with a rising followers chart and notifications for new followers, likes and video views" fetchpriority="high" decoding="async">
    </div>
  </div>
</section>

<?php if ($platforms): ?>
<section class="section section-alt" aria-labelledby="platforms-title">
  <div class="container">
    <div class="section-head"><h2 id="platforms-title">Supported social platforms</h2><p>Services are grouped by platform. Pick one to see its services and prices.</p></div>
    <ul class="platform-grid">
      <?php foreach ($platforms as $p): ?>
      <li><a class="platform-card" href="<?= e(url('/services?category=' . $p['category'])) ?>">
        <span class="platform-card-icon"><?= Platforms::icon($p['key']) ?></span>
        <span class="platform-card-text"><strong><?= e($p['key'] === 'other' ? 'More services' : $p['label']) ?></strong><small><?= number_format($p['services']) ?> service<?= $p['services'] === 1 ? '' : 's' ?></small></span>
      </a></li>
      <?php endforeach ?>
    </ul>
  </div>
</section>
<?php endif ?>

<section class="section" aria-labelledby="why-title">
  <div class="container">
    <div class="section-head"><h2 id="why-title">Why choose <?= e(site_name()) ?></h2><p>Built for creators, agencies, freelancers and panel owners who need reliable delivery and clean accounting.</p></div>
    <div class="features">
      <?php foreach ([
          ['zap', 'Instant, automatic ordering', 'Choose a service, paste a link and confirm the exact server-calculated price. Orders go to the provider immediately.'],
          ['refresh', 'Refunds you do not have to ask for', 'If an order is only partly delivered or cancelled, the difference returns to your balance automatically.'],
          ['clock', 'Auto-subscriptions', 'Repeat an order on a schedule (hourly to weekly) for steady, natural-looking growth. Pause or cancel any time.'],
          ['code', 'Reseller API', 'Connect your own panel with the standard SMM API v2: services, orders, statuses, refills, cancels and balance.'],
          ['wallet', 'Transparent wallet', 'Every balance change is recorded in a ledger you can review. Top up with the payment methods on offer.'],
          ['shield', 'Account security', 'Two-factor authentication, login protection and session controls keep your account and funds safe.'],
      ] as [$ic, $h, $p]): ?>
      <div class="feature"><div class="stat-icon"><?= icon($ic) ?></div><h3><?= e($h) ?></h3><p><?= e($p) ?></p></div>
      <?php endforeach ?>
    </div>
  </div>
</section>

<section class="section section-alt" aria-labelledby="how-title">
  <div class="container">
    <div class="section-head"><h2 id="how-title">How it works</h2><p>From sign-up to your first delivered order in a few minutes.</p></div>
    <ol class="steps">
      <li class="step"><h3>Create an account</h3><p>Sign up free with an email address.</p></li>
      <li class="step"><h3>Add funds</h3><p>Top up your balance with any available payment method.</p></li>
      <li class="step"><h3>Place an order</h3><p>Pick a platform and service, paste your link, set the quantity and confirm.</p></li>
      <li class="step"><h3>Track delivery</h3><p>Follow the status live and request refills where the service offers them.</p></li>
    </ol>
    <?php if ($stats['services'] > 0): ?>
    <dl class="stat-band">
      <div><dt>Services available</dt><dd><?= number_format($stats['services']) ?></dd></div>
      <div><dt>Categories</dt><dd><?= number_format($stats['categories']) ?></dd></div>
      <?php if ($stats['orders'] > 0): ?><div><dt>Orders processed</dt><dd><?= number_format($stats['orders']) ?></dd></div><?php endif ?>
    </dl>
    <?php endif ?>
  </div>
</section>

<?php if ($popular): ?>
<section class="section" aria-labelledby="popular-title">
  <div class="container">
    <div class="section-head"><h2 id="popular-title">Popular services</h2><p><?= number_format($stats['services']) ?> services across <?= number_format($stats['categories']) ?> categories.</p></div>
    <div class="card">
      <div class="table-wrap">
        <table class="table table-cards">
          <thead><tr><th>ID</th><th>Service</th><th>Category</th><th class="num">Rate per 1000</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($popular as $s): ?>
            <tr>
              <td data-label="ID" class="mono"><?= (int) $s['id'] ?></td>
              <td class="cell-main"><span class="cell-title heading-icon"><?= Platforms::icon(Platforms::forCategory(['name' => $s['category'], 'platform' => $s['category_platform'] ?? null])) ?> <span><?= e($s['name']) ?></span></span></td>
              <td data-label="Category"><?= e($s['category']) ?></td>
              <td data-label="Rate" class="num fw-bold nowrap"><?= e(rate($s['rate'])) ?></td>
              <td class="actions"><a class="btn btn-soft btn-sm" href="<?= e(url('/order?service=' . (int) $s['id'])) ?>">Order</a></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer text-center"><a href="<?= e(url('/services')) ?>">See all services and prices <?= icon('arrow-right') ?></a></div>
    </div>
  </div>
</section>
<?php endif ?>

<?php if ($faqs): ?>
<section class="section section-alt" aria-labelledby="faq-title">
  <div class="container container-sm">
    <div class="section-head"><h2 id="faq-title">Frequently asked questions</h2></div>
    <div class="faq-list">
      <?php foreach ($faqs as $f): ?>
      <details><summary><?= e($f['question']) ?> <?= icon('chevron-down') ?></summary><div class="answer"><?= nl2br(e($f['answer'])) ?></div></details>
      <?php endforeach ?>
    </div>
    <p class="text-center mt-2"><a href="<?= e(url('/faq')) ?>">All FAQs</a></p>
  </div>
</section>
<?php endif ?>

<?php if ($posts): ?>
<section class="section" aria-labelledby="blog-title">
  <div class="container">
    <div class="section-head"><h2 id="blog-title">From the blog</h2></div>
    <div class="blog-grid">
      <?php foreach ($posts as $p): ?>
      <a class="post-card" href="<?= e(url('/blog/' . $p['slug'])) ?>">
        <?php if ($p['featured_image']): ?><img class="thumb" src="<?= e(upload_url($p['featured_image'])) ?>" alt="" loading="lazy" width="400" height="225"><?php endif ?>
        <div class="body"><div class="meta-line mb-1"><?= e(fmt_date($p['published_at'], 'M j, Y')) ?></div><h3><?= e($p['title']) ?></h3><p><?= e(str_limit($p['excerpt'], 130)) ?></p></div>
      </a>
      <?php endforeach ?>
    </div>
  </div>
</section>
<?php endif ?>

<section class="section">
  <div class="container">
    <div class="cta-band">
      <div><h2>Ready to grow?</h2><p>Create your account in seconds and place your first order today.</p></div>
      <a class="btn btn-secondary btn-lg" href="<?= e($primaryUrl) ?>"><?= $user ? 'New order' : 'Get started free' ?> <?= icon('arrow-right') ?></a>
    </div>
  </div>
</section>
