<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// A one-time portal URL can also appear in a Filament/Livewire result, not just an API response.
final class SensitiveApplicationResponses
{
    public static function appliesTo(Request $request): bool
    {
        return $request->is('webhooks/postmark/bounces', 'api/leads', 'admin', 'admin/*', 'portal', 'portal/*', 'staff', 'staff/*')
            || str_ends_with((string) $request->route()?->getName(), 'livewire.update');
    }

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (self::appliesTo($request)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            if ($request->is('staff', 'staff/*', 'portal/tip', 'portal/tip/*', 'portal/api/tip', 'portal/api/tip/*')) $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        return $response;
    }
}
