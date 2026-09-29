<?php $this->extend('layouts/auth'); ?>
<h1>Verify your email</h1>
<p class="sub">We sent a verification link to <strong><?= e($user['email']) ?></strong>. Click it to activate your account.</p>
<form method="post" action="<?= e(url('/verify-email/resend')) ?>" class="mb-2"><?= csrf_field() ?><button class="btn btn-primary btn-block" type="submit">Resend verification email</button></form>
<form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-block" type="submit">Sign out</button></form>
