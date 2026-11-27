<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Audit 9 nov (a9-seguridad-crm): every CRM page gets the same basic headers as the website — never shown inside another
// site (clickjacking), no MIME sniffing, a privacy-preserving referrer, and HTTPS remembered by the browser.
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('X-Content-Type-Options', 'nosniff');
        if (! $h->has('Referrer-Policy')) $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        if ($request->isSecure()) $h->set('Strict-Transport-Security', 'max-age=31536000');
        return $response;
    }
}
