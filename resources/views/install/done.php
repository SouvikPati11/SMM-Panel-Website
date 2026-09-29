<?php $this->extend('install/layout'); \App\Core\View::share('step', 4); ?>
<div class="card"><div class="card-body">
  <div class="alert alert-success"><?= icon('check-circle') ?><div><div class="alert-title">Installation complete</div>The installer is now locked (storage/installed.lock) and /install returns 404.</div></div>
  <?php if (!$envWritten): ?>
    <div class="alert alert-warning"><?= icon('alert') ?><div><div class="alert-title">Create the .env file manually</div>The installer could not write <code>.env</code>. Create a file named <code>.env</code> in <code><?= e($basePath) ?></code> with exactly this content, then open your site:</div></div>
    <textarea class="textarea code" rows="18" readonly><?= e($env) ?></textarea>
  <?php endif ?>
  <h3 class="mt-3">Next steps</h3>
  <ol>
    <li>Sign in to the admin panel: <a href="<?= e($adminUrl) ?>"><?= e($adminUrl) ?></a> (bookmark it — it is not linked publicly).</li>
    <li>Add one cron job that runs every minute (Hostinger: Advanced → Cron Jobs → Custom; cPanel: Cron Jobs). Use the PHP CLI binary, not <code>lsphp</code>. Details: <code>docs/cron.md</code> and Admin → Cron tasks.
      <pre><code><?= e($phpBinary) ?> <?= e($basePath) ?>/cron/run.php</code></pre></li>
    <li>Configure SMTP (Admin → Email), payment gateways (Admin → Payment gateways) and your first provider (Admin → Providers).</li>
    <li>Enable SSL and keep <code>FORCE_HTTPS=true</code>.</li>
  </ol>
  <a class="btn btn-primary" href="<?= e($adminUrl) ?>">Open admin panel</a>
</div></div>
