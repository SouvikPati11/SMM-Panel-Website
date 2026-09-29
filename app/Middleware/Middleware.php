<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

interface Middleware
{
    public function handle(Request $request, \Closure $next, ?string $arg = null): Response;
}
