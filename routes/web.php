<?php

/** @var App\Core\Router $router */

use App\Controllers\Public\AuthController;
use App\Controllers\Public\BlogController;
use App\Controllers\Public\PageController;
use App\Controllers\User;
use App\Controllers\Webhook\WebhookController;

// ------------------------------------------------------------------ stateless endpoints
$router->get('/sitemap.xml', [PageController::class, 'sitemap'], 'sitemap', ['stateless']);
$router->get('/robots.txt', [PageController::class, 'robots'], 'robots', ['stateless']);
// GET is accepted too: P2Gateway's callback method is undocumented. Callbacks are
// never trusted on their own (signature and/or server-side status check).
$router->any('/webhooks/{gateway}', [WebhookController::class, 'handle'], 'webhook', ['stateless']);
$router->get('/tasks/run/{token}', [App\Controllers\Public\CronController::class, 'run'], 'cron.http', ['stateless']);

// ------------------------------------------------------------------ public website
$router->group(['middleware' => ['maintenance', 'csrf']], static function ($router) {
    $router->get('/', [PageController::class, 'home'], 'home');
    $router->get('/services', [PageController::class, 'services'], 'services');
    $router->get('/faq', [PageController::class, 'faq'], 'faq');
    $router->get('/about', [PageController::class, 'about'], 'about');
    $router->get('/terms', [PageController::class, 'terms'], 'terms');
    $router->get('/privacy', [PageController::class, 'privacy'], 'privacy');
    $router->get('/refund-policy', [PageController::class, 'refund'], 'refund');
    $router->get('/contact', [PageController::class, 'contact'], 'contact');
    $router->post('/contact', [PageController::class, 'sendContact'], 'contact.send', ['throttle:contact,3,600']);
    $router->get('/api-docs', [PageController::class, 'apiDocs'], 'api.docs');
    $router->get('/page/{slug}', [PageController::class, 'page'], 'page');
    $router->get('/r/{token}', [AuthController::class, 'referral'], 'referral.link');

    $router->get('/blog', [BlogController::class, 'index'], 'blog');
    $router->get('/blog/category/{slug}', [BlogController::class, 'category'], 'blog.category');
    $router->get('/blog/tag/{slug}', [BlogController::class, 'tag'], 'blog.tag');
    $router->get('/blog/{slug}', [BlogController::class, 'show'], 'blog.show');

    // Authentication
    $router->group(['middleware' => ['guest']], static function ($router) {
        $router->get('/login', [AuthController::class, 'loginForm'], 'login');
        $router->post('/login', [AuthController::class, 'login'], 'login.post', ['throttle:login,10,60']);
        $router->get('/login/2fa', [AuthController::class, 'twoFactorForm'], 'login.2fa');
        $router->post('/login/2fa', [AuthController::class, 'twoFactor'], 'login.2fa.post', ['throttle:2fa,6,60']);
        $router->get('/register', [AuthController::class, 'registerForm'], 'register');
        $router->post('/register', [AuthController::class, 'register'], 'register.post', ['throttle:register,5,600']);
        $router->get('/forgot-password', [AuthController::class, 'forgotForm'], 'password.forgot');
        $router->post('/forgot-password', [AuthController::class, 'forgot'], 'password.forgot.post', ['throttle:forgot,5,600']);
        $router->get('/reset-password/{token}', [AuthController::class, 'resetForm'], 'password.reset');
        $router->post('/reset-password/{token}', [AuthController::class, 'reset'], 'password.reset.post', ['throttle:reset,10,600']);
    });
    $router->get('/verify-email/{token}', [AuthController::class, 'verifyEmail'], 'verify.email.token');
    // Sign in with Google (OAuth 2.0 / OpenID Connect)
    $router->get('/auth/google', [AuthController::class, 'google'], 'auth.google', ['throttle:oauth,20,600']);
    $router->get('/auth/google/callback', [AuthController::class, 'googleCallback'], 'auth.google.callback', ['throttle:oauthcb,20,600']);
    $router->group(['middleware' => ['guest']], static function ($router) {
        $router->get('/auth/google/complete', [AuthController::class, 'googleCompleteForm'], 'auth.google.complete');
        $router->post('/auth/google/complete', [AuthController::class, 'googleComplete'], 'auth.google.complete.post', ['throttle:register,5,600']);
    });
});

// ------------------------------------------------------------------ user panel
$router->group(['middleware' => ['maintenance', 'csrf', 'auth']], static function ($router) {
    $router->post('/logout', [AuthController::class, 'logout'], 'logout');
    $router->post('/account/google/connect', [AuthController::class, 'googleConnect'], 'account.google.connect', ['throttle:oauth,20,600']);
    $router->post('/account/google/disconnect', [AuthController::class, 'googleDisconnect'], 'account.google.disconnect');
    $router->get('/verify-email', [AuthController::class, 'verifyNotice'], 'verify.notice');
    $router->post('/verify-email/resend', [AuthController::class, 'resendVerification'], 'verify.resend', ['throttle:verify,3,600']);

    $router->group(['middleware' => ['verified']], static function ($router) {
        $router->get('/dashboard', [User\DashboardController::class, 'index'], 'dashboard');

        $router->get('/order', [User\OrderController::class, 'create'], 'order.new');
        $router->post('/order', [User\OrderController::class, 'store'], 'order.store', ['throttle:order,30,60']);
        $router->get('/order/service/{id}', [User\OrderController::class, 'serviceInfo'], 'order.service');
        $router->post('/order/quote', [User\OrderController::class, 'quote'], 'order.quote', ['throttle:quote,120,60']);
        $router->get('/subscriptions', [User\SubscriptionController::class, 'index'], 'subscriptions');
        $router->get('/subscriptions/{id}', [User\SubscriptionController::class, 'show'], 'subscriptions.show');
        $router->post('/subscriptions/{id}/action', [User\SubscriptionController::class, 'action'], 'subscriptions.action', ['throttle:subaction,20,60']);
        $router->get('/mass-order', [User\OrderController::class, 'massForm'], 'order.mass');
        $router->post('/mass-order', [User\OrderController::class, 'massStore'], 'order.mass.store', ['throttle:mass,5,60']);
        $router->get('/catalog', [User\OrderController::class, 'catalog'], 'catalog');
        $router->get('/orders', [User\OrderController::class, 'index'], 'orders');
        $router->get('/orders/{id}', [User\OrderController::class, 'show'], 'orders.show');
        $router->post('/orders/{id}/cancel', [User\OrderController::class, 'cancel'], 'orders.cancel', ['throttle:cancel,10,60']);
        $router->post('/orders/{id}/refill', [User\OrderController::class, 'refill'], 'orders.refill', ['throttle:refill,10,60']);
        $router->get('/refills', [User\OrderController::class, 'refills'], 'refills');

        $router->get('/funds', [User\FundsController::class, 'index'], 'funds');
        $router->post('/funds', [User\FundsController::class, 'pay'], 'funds.pay', ['throttle:funds,10,300']);
        $router->post('/funds/manual', [User\FundsController::class, 'manual'], 'funds.manual', ['throttle:manual,5,600']);
        $router->post('/funds/coupon', [User\FundsController::class, 'checkCoupon'], 'funds.coupon', ['throttle:coupon,15,300']);
        $router->get('/funds/return/{id}', [User\FundsController::class, 'returned'], 'funds.return');
        $router->get('/transactions', [User\FundsController::class, 'transactions'], 'transactions');

        $router->get('/tickets', [User\TicketController::class, 'index'], 'tickets');
        $router->get('/tickets/new', [User\TicketController::class, 'create'], 'tickets.new');
        $router->post('/tickets', [User\TicketController::class, 'store'], 'tickets.store', ['throttle:ticket,5,600']);
        $router->get('/tickets/{id}', [User\TicketController::class, 'show'], 'tickets.show');
        $router->post('/tickets/{id}/reply', [User\TicketController::class, 'reply'], 'tickets.reply', ['throttle:ticketreply,20,600']);
        $router->post('/tickets/{id}/close', [User\TicketController::class, 'close'], 'tickets.close');
        $router->get('/tickets/attachment/{id}', [User\TicketController::class, 'attachment'], 'tickets.attachment');

        $router->get('/affiliates', [User\AccountController::class, 'affiliates'], 'affiliates');
        $router->post('/affiliates/withdraw', [User\AccountController::class, 'affiliateWithdraw'], 'affiliates.withdraw', ['throttle:affwd,5,600']);

        $router->get('/account/api', [User\AccountController::class, 'api'], 'account.api');
        $router->post('/account/api/generate', [User\AccountController::class, 'apiGenerate'], 'account.api.generate', ['throttle:apikey,5,600']);
        $router->post('/account/api/revoke', [User\AccountController::class, 'apiRevoke'], 'account.api.revoke');

        $router->get('/account', [User\AccountController::class, 'profile'], 'account');
        $router->post('/account', [User\AccountController::class, 'updateProfile'], 'account.update');
        $router->post('/account/currency', [User\AccountController::class, 'currency'], 'account.currency', ['throttle:currency,20,60']);
        $router->get('/account/security', [User\AccountController::class, 'security'], 'account.security');
        $router->post('/account/password', [User\AccountController::class, 'password'], 'account.password', ['throttle:pwd,5,600']);
        $router->post('/account/2fa/enable', [User\AccountController::class, 'enable2fa'], 'account.2fa.enable', ['throttle:2fae,10,600']);
        $router->post('/account/2fa/disable', [User\AccountController::class, 'disable2fa'], 'account.2fa.disable', ['throttle:2fad,5,600']);
        $router->post('/account/sessions/revoke', [User\AccountController::class, 'logoutOthers'], 'account.sessions.revoke');

        $router->get('/notifications', [User\AccountController::class, 'notifications'], 'notifications');
        $router->post('/notifications/read', [User\AccountController::class, 'readNotifications'], 'notifications.read');
    });
});
