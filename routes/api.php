<?php

/** @var App\Core\Router $router */

use App\Controllers\Api\ApiV2Controller;

// Reseller API (standard SMM panel v2 format). Stateless: no session, no CSRF;
// authenticated by API key and rate limited per key/IP inside the controller.
$router->any('/api/v2', [ApiV2Controller::class, 'handle'], 'api.v2', ['stateless']);
