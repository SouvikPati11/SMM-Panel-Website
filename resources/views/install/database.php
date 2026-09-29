<?php $this->extend('install/layout'); \App\Core\View::share('step', 2); use App\Helpers\Form; ?>
<div class="card"><div class="card-header"><h2>Database connection</h2></div><div class="card-body">
  <p class="text-muted">Create an empty MySQL/MariaDB database and user in cPanel → MySQL® Databases (or Hostinger → Databases), grant the user ALL PRIVILEGES, then enter the details below.</p>
  <form method="post" action="<?= e(url('/install/database')) ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
      <?= Form::input('host', 'Host', $db['host'] ?? 'localhost', ['required' => true, 'hint' => 'Usually "localhost".']) ?>
      <?= Form::input('port', 'Port', $db['port'] ?? '3306', ['type' => 'number']) ?>
      <?= Form::input('name', 'Database name', $db['name'] ?? '', ['required' => true, 'hint' => 'e.g. u123456_smm']) ?>
      <?= Form::input('user', 'Database user', $db['user'] ?? '', ['required' => true]) ?>
    </div>
    <?= Form::input('pass', 'Database password', '', ['type' => 'password', 'autocomplete' => 'off']) ?>
    <button class="btn btn-primary" type="submit">Test & continue</button>
  </form>
</div></div>
