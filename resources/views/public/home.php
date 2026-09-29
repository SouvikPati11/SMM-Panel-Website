<?php $this->extend('layouts/public'); ?>
<section class="hero">
  <div class="container hero-inner">
    <div>
      <span class="eyebrow"><span class="dot"></span> Orders processed automatically, 24/7</span>
      <h1>Social media growth, <span class="accent">wholesale priced</span> and fully automated.</h1>
      <p class="lead"><?= e(setting('site_tagline')) ?>. Transparent pricing, instant delivery, automatic refunds and an API your panel already understands.</p>
      <div class="hero-cta">
        <a class="btn btn-primary btn-lg" href="<?= e(url(auth_user() ? '/order' : '/register')) ?>"><?= icon('zap') ?> <?= auth_user() ? 'Place an order' : 'Create free account' ?></a>
        <a class="btn btn-secondary btn-lg" href="<?= e(url('/services')) ?>">View services & prices</a>
      </div>
      <div class="hero-trust">
        <span><?= icon('check-circle') ?> No monthly fees</span>
        <span><?= icon('check-circle') ?> Auto-refund on partial orders</span>
        <span><?= icon('check-circle') ?> Reseller API</span>
      </div>
    </div>
    <div class="hero-card" aria-hidden="true">
      <div class="flex justify-between items-center mb-2">
        <strong>Order tracking</strong><span class="badge badge-muted no-dot">Example</span>
      </div>
      <?php $demo = [['Instagram Followers', '2,500', 'completed'], ['TikTok Views', '50,000', 'in_progress'], ['YouTube Likes', '1,000', 'processing'], ['Telegram Members', '5,000', 'completed']];
      foreach ($demo as [$n, $q, $s]): ?>
      <div class="mock-row"><div><div class="cell-title"><?= e($n) ?></div><div class="cell-sub"><?= e($q) ?> units</div></div><?= status_badge($s) ?></div>
      <?php endforeach ?>
    </div>
  </div>
</section>

<section class="section section-alt">
  <div class="container">
    <div class="section-head">
      <h2>Everything a reseller needs</h2>
      <p>Built for agencies, freelancers and panel owners who need reliable delivery and clean accounting.</p>
    </div>
    <div class="features">
      <?php foreach ([
          ['zap', 'Instant ordering', 'Pick a service, paste a link and see the exact price before you pay. Mass order up to hundreds of links at once.'],
          ['refresh', 'Automatic refunds', 'Partial deliveries and cancellations are refunded to your balance automatically — to the cent.'],
          ['code', 'Standard API v2', 'Plug our API into any panel script. Services, orders, statuses, refills, cancels and balance.'],
          ['wallet', 'Secure wallet', 'Every balance change is recorded in an auditable ledger. Top up with crypto or manual methods.'],
          ['shield', 'Account security', 'Two-factor authentication, login protection and session controls keep your funds safe.'],
          ['chat', 'Real support', 'Ticket-based support with order references so issues get solved quickly.'],
      ] as [$ic, $h, $p]): ?>
      <div class="feature"><div class="stat-icon"><?= icon($ic) ?></div><h3><?= e($h) ?></h3><p><?= e($p) ?></p></div>
      <?php endforeach ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-head"><h2>Start in four steps</h2><p>From sign-up to your first delivered order in a few minutes.</p></div>
    <div class="steps">
      <div class="step"><h3>Create an account</h3><p>Sign up free with just an email address.</p></div>
      <div class="step"><h3>Add funds</h3><p>Top up your balance with any available payment method.</p></div>
      <div class="step"><h3>Place an order</h3><p>Choose a service, paste your link and set a quantity.</p></div>
      <div class="step"><h3>Track delivery</h3><p>Watch status updates live, request refills when available.</p></div>
    </div>
  </div>
</section>

<?php if ($popular): ?>
<section class="section section-alt">
  <div class="container">
    <div class="section-head"><h2>Popular services</h2><p><?= number_format($stats['services']) ?> services across <?= number_format($stats['categories']) ?> categories.</p></div>
    <div class="card">
      <div class="table-wrap">
        <table class="table table-cards">
          <thead><tr><th>ID</th><th>Service</th><th>Category</th><th class="num">Rate per 1000</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($popular as $s): ?>
            <tr>
              <td data-label="ID" class="mono"><?= (int) $s['id'] ?></td>
              <td class="cell-main"><span class="cell-title"><?= e($s['name']) ?></span></td>
              <td data-label="Category"><?= e($s['category']) ?></td>
              <td data-label="Rate" class="num fw-bold"><?= e(rate($s['rate'])) ?></td>
              <td class="actions"><a class="btn btn-soft btn-sm" href="<?= e(url('/order?service=' . (int) $s['id'])) ?>">Order</a></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer text-center"><a href="<?= e(url('/services')) ?>">See the full catalog <?= icon('arrow-right') ?></a></div>
    </div>
  </div>
</section>
<?php endif ?>

<?php if ($faqs): ?>
<section class="section">
  <div class="container container-sm">
    <div class="section-head"><h2>Questions, answered</h2></div>
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
<section class="section section-alt">
  <div class="container">
    <div class="section-head"><h2>From the blog</h2></div>
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
      <a class="btn btn-secondary btn-lg" href="<?= e(url(auth_user() ? '/order' : '/register')) ?>"><?= auth_user() ? 'New order' : 'Get started free' ?> <?= icon('arrow-right') ?></a>
    </div>
  </div>
</section>
