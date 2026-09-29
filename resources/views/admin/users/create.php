<?php $this->extend('layouts/admin'); use App\Helpers\Form; ?>
<div class="page-head"><div><ol class="breadcrumb"><li><a href="<?= e(admin_url('users')) ?>">Users</a></li><li>Create</li></ol><h1>Create user</h1></div></div>
<div class="card" style="max-width:640px"><div class="card-body">
  <form method="post" action="<?= e(admin_url('users/create')) ?>"><?= csrf_field() ?>
    <div class="form-grid"><?= Form::input('username', 'Username', '', ['required' => true]) ?><?= Form::input('email', 'Email', '', ['type' => 'email', 'required' => true]) ?></div>
    <?= Form::input('password', 'Initial password', '', ['type' => 'password', 'required' => true, 'hint' => 'Share it securely; the user should change it after first login.']) ?>
    <?= Form::select('price_level_id', 'Price level', $levels, '', ['empty' => 'Default']) ?>
    <button class="btn btn-primary" type="submit">Create user</button>
  </form>
</div></div>
