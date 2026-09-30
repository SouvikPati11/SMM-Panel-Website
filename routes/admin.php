<?php

/** @var App\Core\Router $router */

use App\Controllers\Admin;

$prefix = '/' . admin_path();

$router->group(['prefix' => $prefix, 'middleware' => ['csrf']], static function ($router) {
    // Admin authentication (separate from user auth)
    $router->group(['middleware' => ['admin.guest']], static function ($router) {
        $router->get('/login', [Admin\AuthController::class, 'loginForm'], 'admin.login');
        $router->post('/login', [Admin\AuthController::class, 'login'], 'admin.login.post', ['throttle:adminlogin,8,300']);
        $router->get('/login/2fa', [Admin\AuthController::class, 'twoFactorForm'], 'admin.2fa');
        $router->post('/login/2fa', [Admin\AuthController::class, 'twoFactor'], 'admin.2fa.post', ['throttle:admin2fa,6,300']);
        $router->get('/forgot-password', [Admin\AuthController::class, 'forgotForm'], 'admin.forgot');
        $router->post('/forgot-password', [Admin\AuthController::class, 'forgot'], 'admin.forgot.post', ['throttle:adminforgot,3,600']);
        $router->get('/reset-password/{token}', [Admin\AuthController::class, 'resetForm'], 'admin.reset');
        $router->post('/reset-password/{token}', [Admin\AuthController::class, 'reset'], 'admin.reset.post', ['throttle:adminreset,5,600']);
    });

    $router->group(['middleware' => ['admin']], static function ($router) {
        $router->post('/logout', [Admin\AuthController::class, 'logout'], 'admin.logout');
        $router->get('/', [Admin\DashboardController::class, 'index'], 'admin.dashboard');
        $router->get('/account', [Admin\AuthController::class, 'account'], 'admin.account');
        $router->post('/account/password', [Admin\AuthController::class, 'password'], 'admin.account.password');
        $router->post('/account/2fa/enable', [Admin\AuthController::class, 'enable2fa'], 'admin.account.2fa.enable');
        $router->post('/account/2fa/disable', [Admin\AuthController::class, 'disable2fa'], 'admin.account.2fa.disable');

        // Users
        $router->get('/users', [Admin\UserController::class, 'index'], 'admin.users', ['perm:users.view']);
        $router->get('/users/create', [Admin\UserController::class, 'create'], 'admin.users.create', ['perm:users.manage']);
        $router->post('/users/create', [Admin\UserController::class, 'store'], 'admin.users.store', ['perm:users.manage']);
        $router->get('/users/{id}', [Admin\UserController::class, 'show'], 'admin.users.show', ['perm:users.view']);
        $router->post('/users/{id}', [Admin\UserController::class, 'update'], 'admin.users.update', ['perm:users.manage']);
        $router->post('/users/{id}/balance', [Admin\UserController::class, 'balance'], 'admin.users.balance', ['perm:users.balance']);
        $router->post('/users/{id}/security', [Admin\UserController::class, 'security'], 'admin.users.security', ['perm:users.manage']);
        $router->post('/users/{id}/delete', [Admin\UserController::class, 'delete'], 'admin.users.delete', ['perm:users.manage']);
        $router->get('/balances', [Admin\UserController::class, 'balances'], 'admin.balances', ['perm:users.view']);

        // Orders & refills
        $router->get('/orders', [Admin\OrderController::class, 'index'], 'admin.orders', ['perm:orders.view']);
        $router->get('/orders/{id}', [Admin\OrderController::class, 'show'], 'admin.orders.show', ['perm:orders.view']);
        $router->post('/orders/{id}/status', [Admin\OrderController::class, 'status'], 'admin.orders.status', ['perm:orders.manage']);
        $router->post('/orders/{id}/refund', [Admin\OrderController::class, 'refund'], 'admin.orders.refund', ['perm:orders.manage']);
        $router->post('/orders/{id}/resolve', [Admin\OrderController::class, 'resolve'], 'admin.orders.resolve', ['perm:orders.manage']);
        $router->post('/orders/{id}/sync', [Admin\OrderController::class, 'sync'], 'admin.orders.sync', ['perm:orders.manage']);
        $router->get('/subscriptions', [Admin\SubscriptionController::class, 'index'], 'admin.subscriptions', ['perm:orders.view']);
        $router->get('/subscriptions/{id}', [Admin\SubscriptionController::class, 'show'], 'admin.subscriptions.show', ['perm:orders.view']);
        $router->post('/subscriptions/{id}/action', [Admin\SubscriptionController::class, 'action'], 'admin.subscriptions.action', ['perm:orders.manage']);
        $router->get('/refills', [Admin\OrderController::class, 'refills'], 'admin.refills', ['perm:orders.view']);
        $router->post('/refills/{id}', [Admin\OrderController::class, 'refillUpdate'], 'admin.refills.update', ['perm:orders.manage']);

        // Catalog
        $router->get('/services', [Admin\ServiceController::class, 'index'], 'admin.services', ['perm:services.manage']);
        $router->get('/services/create', [Admin\ServiceController::class, 'create'], 'admin.services.create', ['perm:services.manage']);
        $router->get('/services/{id}/edit', [Admin\ServiceController::class, 'edit'], 'admin.services.edit', ['perm:services.manage']);
        $router->post('/services/save', [Admin\ServiceController::class, 'save'], 'admin.services.save', ['perm:services.manage']);
        $router->post('/services/bulk', [Admin\ServiceController::class, 'bulk'], 'admin.services.bulk', ['perm:services.manage']);
        $router->post('/services/{id}/delete', [Admin\ServiceController::class, 'delete'], 'admin.services.delete', ['perm:services.manage']);
        $router->get('/categories', [Admin\ServiceController::class, 'categories'], 'admin.categories', ['perm:services.manage']);
        $router->post('/categories/save', [Admin\ServiceController::class, 'saveCategory'], 'admin.categories.save', ['perm:services.manage']);
        $router->post('/categories/{id}/delete', [Admin\ServiceController::class, 'deleteCategory'], 'admin.categories.delete', ['perm:services.manage']);

        $router->get('/providers', [Admin\ProviderController::class, 'index'], 'admin.providers', ['perm:providers.manage']);
        $router->get('/providers/create', [Admin\ProviderController::class, 'create'], 'admin.providers.create', ['perm:providers.manage']);
        $router->get('/providers/{id}/edit', [Admin\ProviderController::class, 'edit'], 'admin.providers.edit', ['perm:providers.manage']);
        $router->post('/providers/save', [Admin\ProviderController::class, 'save'], 'admin.providers.save', ['perm:providers.manage']);
        $router->post('/providers/{id}/delete', [Admin\ProviderController::class, 'delete'], 'admin.providers.delete', ['perm:providers.manage']);
        $router->post('/providers/{id}/check', [Admin\ProviderController::class, 'check'], 'admin.providers.check', ['perm:providers.manage']);
        $router->post('/providers/{id}/fetch', [Admin\ProviderController::class, 'fetch'], 'admin.providers.fetch', ['perm:providers.manage']);
        $router->get('/providers/{id}/services', [Admin\ProviderController::class, 'catalog'], 'admin.providers.catalog', ['perm:providers.manage']);
        $router->post('/providers/{id}/import', [Admin\ProviderController::class, 'import'], 'admin.providers.import', ['perm:providers.manage']);
        $router->post('/providers/sync-prices', [Admin\ProviderController::class, 'syncPrices'], 'admin.providers.syncprices', ['perm:providers.manage']);
        $router->get('/providers/logs', [Admin\ProviderController::class, 'logs'], 'admin.providers.logs', ['perm:providers.manage']);

        // Finance
        $router->get('/payments', [Admin\PaymentController::class, 'index'], 'admin.payments', ['perm:payments.view']);
        $router->post('/payments/{id}/verify', [Admin\PaymentController::class, 'verify'], 'admin.payments.verify', ['perm:payments.manage']);
        $router->get('/payments/{id}', [Admin\PaymentController::class, 'show'], 'admin.payments.show', ['perm:payments.view']);
        $router->post('/payments/{id}/review/approve', [Admin\PaymentController::class, 'reviewApprove'], 'admin.payments.review.approve', ['perm:payments.manage']);
        $router->post('/payments/{id}/review/reject', [Admin\PaymentController::class, 'reviewReject'], 'admin.payments.review.reject', ['perm:payments.manage']);
        $router->post('/payments/{id}/review/release', [Admin\PaymentController::class, 'reviewRelease'], 'admin.payments.review.release', ['perm:payments.manage']);
        $router->get('/manual-payments', [Admin\PaymentController::class, 'manual'], 'admin.manual', ['perm:payments.view']);
        $router->post('/manual-payments/{id}/approve', [Admin\PaymentController::class, 'approve'], 'admin.manual.approve', ['perm:payments.manage']);
        $router->post('/manual-payments/{id}/reject', [Admin\PaymentController::class, 'reject'], 'admin.manual.reject', ['perm:payments.manage']);
        $router->get('/manual-payments/{id}/proof', [Admin\PaymentController::class, 'proof'], 'admin.manual.proof', ['perm:payments.view']);
        $router->get('/gateways', [Admin\GatewayController::class, 'index'], 'admin.gateways', ['perm:gateways.manage']);
        $router->get('/gateways/create', [Admin\GatewayController::class, 'create'], 'admin.gateways.create', ['perm:gateways.manage']);
        $router->get('/gateways/{id}/edit', [Admin\GatewayController::class, 'edit'], 'admin.gateways.edit', ['perm:gateways.manage']);
        $router->post('/gateways/save', [Admin\GatewayController::class, 'save'], 'admin.gateways.save', ['perm:gateways.manage']);
        $router->post('/gateways/{id}/delete', [Admin\GatewayController::class, 'delete'], 'admin.gateways.delete', ['perm:gateways.manage']);
        $router->get('/transactions', [Admin\PaymentController::class, 'transactions'], 'admin.transactions', ['perm:transactions.view']);
        $router->get('/coupons', [Admin\MarketingController::class, 'coupons'], 'admin.coupons', ['perm:coupons.manage']);
        $router->post('/coupons/save', [Admin\MarketingController::class, 'saveCoupon'], 'admin.coupons.save', ['perm:coupons.manage']);
        $router->post('/coupons/{id}/delete', [Admin\MarketingController::class, 'deleteCoupon'], 'admin.coupons.delete', ['perm:coupons.manage']);
        $router->get('/affiliates', [Admin\MarketingController::class, 'affiliates'], 'admin.affiliates', ['perm:affiliates.manage']);
        $router->post('/affiliates/{id}/block', [Admin\MarketingController::class, 'blockReferral'], 'admin.affiliates.block', ['perm:affiliates.manage']);

        // Support & communication
        $router->get('/tickets', [Admin\TicketController::class, 'index'], 'admin.tickets', ['perm:tickets.manage']);
        $router->get('/tickets/{id}', [Admin\TicketController::class, 'show'], 'admin.tickets.show', ['perm:tickets.manage']);
        $router->post('/tickets/{id}/reply', [Admin\TicketController::class, 'reply'], 'admin.tickets.reply', ['perm:tickets.manage']);
        $router->post('/tickets/{id}/update', [Admin\TicketController::class, 'update'], 'admin.tickets.update', ['perm:tickets.manage']);
        $router->post('/tickets/message/{id}/delete', [Admin\TicketController::class, 'deleteMessage'], 'admin.tickets.msgdelete', ['perm:tickets.manage']);
        $router->get('/tickets/attachment/{id}', [Admin\TicketController::class, 'attachment'], 'admin.tickets.attachment', ['perm:tickets.manage']);
        $router->get('/notifications', [Admin\MarketingController::class, 'notifications'], 'admin.notifications', ['perm:notifications.manage']);
        $router->post('/notifications/announcement', [Admin\MarketingController::class, 'saveAnnouncement'], 'admin.announcements.save', ['perm:notifications.manage']);
        $router->post('/notifications/announcement/{id}/delete', [Admin\MarketingController::class, 'deleteAnnouncement'], 'admin.announcements.delete', ['perm:notifications.manage']);
        $router->post('/notifications/send', [Admin\MarketingController::class, 'sendNotification'], 'admin.notifications.send', ['perm:notifications.manage']);

        // Content
        $router->get('/pages', [Admin\ContentController::class, 'pages'], 'admin.pages', ['perm:content.manage']);
        $router->get('/pages/create', [Admin\ContentController::class, 'pageForm'], 'admin.pages.create', ['perm:content.manage']);
        $router->get('/pages/{id}/edit', [Admin\ContentController::class, 'pageForm'], 'admin.pages.edit', ['perm:content.manage']);
        $router->post('/pages/save', [Admin\ContentController::class, 'savePage'], 'admin.pages.save', ['perm:content.manage']);
        $router->post('/pages/{id}/delete', [Admin\ContentController::class, 'deletePage'], 'admin.pages.delete', ['perm:content.manage']);
        $router->get('/faq', [Admin\ContentController::class, 'faqs'], 'admin.faq', ['perm:content.manage']);
        $router->post('/faq/save', [Admin\ContentController::class, 'saveFaq'], 'admin.faq.save', ['perm:content.manage']);
        $router->post('/faq/{id}/delete', [Admin\ContentController::class, 'deleteFaq'], 'admin.faq.delete', ['perm:content.manage']);
        $router->get('/blog', [Admin\ContentController::class, 'posts'], 'admin.blog', ['perm:content.manage']);
        $router->get('/blog/create', [Admin\ContentController::class, 'postForm'], 'admin.blog.create', ['perm:content.manage']);
        $router->get('/blog/{id}/edit', [Admin\ContentController::class, 'postForm'], 'admin.blog.edit', ['perm:content.manage']);
        $router->post('/blog/save', [Admin\ContentController::class, 'savePost'], 'admin.blog.save', ['perm:content.manage']);
        $router->post('/blog/{id}/delete', [Admin\ContentController::class, 'deletePost'], 'admin.blog.delete', ['perm:content.manage']);
        $router->post('/blog/{id}/toggle', [Admin\ContentController::class, 'togglePost'], 'admin.blog.toggle', ['perm:content.manage']);
        $router->post('/blog/categories/save', [Admin\ContentController::class, 'saveBlogCategory'], 'admin.blog.categories.save', ['perm:content.manage']);
        $router->post('/blog/categories/{id}/delete', [Admin\ContentController::class, 'deleteBlogCategory'], 'admin.blog.categories.delete', ['perm:content.manage']);

        // Settings
        $router->get('/settings', [Admin\SettingsController::class, 'index'], 'admin.settings', ['perm:settings.manage']);
        $router->post('/settings', [Admin\SettingsController::class, 'save'], 'admin.settings.save', ['perm:settings.manage']);
        $router->get('/settings/email', [Admin\SettingsController::class, 'email'], 'admin.settings.email', ['perm:settings.manage']);
        $router->post('/settings/email', [Admin\SettingsController::class, 'saveEmail'], 'admin.settings.email.save', ['perm:settings.manage']);
        $router->post('/settings/email/test', [Admin\SettingsController::class, 'testEmail'], 'admin.settings.email.test', ['perm:settings.manage', 'throttle:testmail,5,300']);
        $router->get('/seo', [Admin\SettingsController::class, 'seo'], 'admin.seo', ['perm:seo.manage']);
        $router->post('/seo', [Admin\SettingsController::class, 'saveSeo'], 'admin.seo.save', ['perm:seo.manage']);
        $router->get('/price-levels', [Admin\SettingsController::class, 'levels'], 'admin.levels', ['perm:settings.manage']);
        $router->post('/price-levels/save', [Admin\SettingsController::class, 'saveLevel'], 'admin.levels.save', ['perm:settings.manage']);
        $router->post('/price-levels/{id}/delete', [Admin\SettingsController::class, 'deleteLevel'], 'admin.levels.delete', ['perm:settings.manage']);
        $router->get('/currencies', [Admin\SettingsController::class, 'currencies'], 'admin.currencies', ['perm:settings.manage']);
        $router->post('/currencies/save', [Admin\SettingsController::class, 'saveCurrency'], 'admin.currencies.save', ['perm:settings.manage']);

        // System
        $router->get('/admins', [Admin\AdminController::class, 'index'], 'admin.admins', ['perm:admins.manage']);
        $router->post('/admins/save', [Admin\AdminController::class, 'save'], 'admin.admins.save', ['perm:admins.manage']);
        $router->post('/admins/{id}/delete', [Admin\AdminController::class, 'delete'], 'admin.admins.delete', ['perm:admins.manage']);
        $router->get('/roles', [Admin\AdminController::class, 'roles'], 'admin.roles', ['perm:admins.manage']);
        $router->post('/roles/save', [Admin\AdminController::class, 'saveRole'], 'admin.roles.save', ['perm:admins.manage']);
        $router->post('/roles/{id}/delete', [Admin\AdminController::class, 'deleteRole'], 'admin.roles.delete', ['perm:admins.manage']);
        $router->get('/logs', [Admin\SystemController::class, 'logs'], 'admin.logs', ['perm:logs.view']);
        $router->get('/health', [Admin\SystemController::class, 'health'], 'admin.health', ['perm:system.manage']);
        $router->post('/health/migrate', [Admin\SystemController::class, 'migrate'], 'admin.health.migrate', ['perm:system.manage']);
        $router->get('/cron', [Admin\SystemController::class, 'cron'], 'admin.cron', ['perm:system.manage']);
        $router->post('/cron/{task}/run', [Admin\SystemController::class, 'runCron'], 'admin.cron.run', ['perm:system.manage']);
    });
});
