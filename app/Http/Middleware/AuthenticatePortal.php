<?php
namespace App\Http\Middleware;

use App\Services\Portal\PortalContext;
use Closure;
use Illuminate\Http\Request;

final class AuthenticatePortal
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('portal_context', PortalContext::fromRequest($request));
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
