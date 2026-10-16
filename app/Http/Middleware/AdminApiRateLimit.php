<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Http\Request;

final class AdminApiRateLimit
{
    public function handle(Request $request, Closure $next)
    {
        $budget = in_array($request->method(), ['GET', 'HEAD'], true) ? 'admin-read' : 'admin-write';
        return app(ThrottleRequests::class)->handle($request, $next, $budget);
    }
}
