<?php

declare(strict_types=1);

/*
 * Front controller for deployments whose document root is the repository root
 * (e.g. Hostinger Git deployment into public_html). The root .htaccess routes
 * every non-static request here. It only delegates to the real front
 * controller, so both layouts (document root = repo root or = public/) behave
 * identically.
 */

require __DIR__ . '/public/index.php';
