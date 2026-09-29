<?php $this->extend('install/layout'); \App\Core\View::share('step', 3); use App\Helpers\Form; ?>
<div class="card"><div class="card-header"><h2>Site & administrator</h2></div><div class="card-body">
  <form method="post" action="<?= e(url('/install/site')) ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
      <?= Form::input('site_name', 'Site name', 'SMM Panel', ['required' => true]) ?>
      <?= Form::input('app_url', 'Site URL', $url, ['required' => true, 'hint' => 'Exactly as visitors reach it, e.g. https://example.com (no trailing slash).']) ?>
    </div>
    <?= Form::input('admin_path', 'Admin panel path', 'admin', ['hint' => 'Change it to something non-obvious (e.g. "control-7f3a") to reduce automated attacks.']) ?>
    <hr>
    <h3>Super administrator</h3>
    <div class="form-grid">
      <?= Form::input('admin_username', 'Username', '', ['required' => true]) ?>
      <?= Form::input('admin_email', 'Email', '', ['type' => 'email', 'required' => true]) ?>
      <?= Form::input('admin_password', 'Password', '', ['type' => 'password', 'required' => true, 'hint' => 'At least 10 characters, letters and numbers.']) ?>
      <?= Form::input('admin_password_confirmation', 'Confirm password', '', ['type' => 'password', 'required' => true]) ?>
    </div>
    <button class="btn btn-primary btn-lg" type="submit">Install now</button>
  </form>
</div></div>
