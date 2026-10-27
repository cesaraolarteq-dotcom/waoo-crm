<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => require base_path('routes/webhooks.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(\Illuminate\Http\Middleware\HandleCors::class, \App\Http\Middleware\ServerAvailabilityCors::class);

        // Route middleware are priority-sorted: validate current owner/2FA before consuming API limits.
        $middleware->prependToPriorityList(
            [\Illuminate\Routing\Middleware\ThrottleRequests::class, \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class],
            \App\Http\Middleware\RequireOwnerAdmin::class,
        );
        // Preserve raw provider bodies and authenticate before decoding JSON.
        $middleware->trimStrings(except: [fn (Request $r) => $r->is('webhooks/postmark/bounces', 'webhooks/resend', 'api/availability', 'api/availability/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $r) => $r->is('webhooks/postmark/bounces', 'webhooks/resend', 'api/availability', 'api/availability/*')]);
        $middleware->append(\App\Http\Middleware\SensitiveApplicationResponses::class);
        $middleware->validateCsrfTokens(except: ['webhooks/stripe']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token']);
        // Denials/validation/CSRF failures need the same privacy headers as successful portal responses.
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response) {
            if (request()->is('webhooks/resend') && $response->getStatusCode() >= 400) {
                $status = $response->getStatusCode();
                $headers = $response->headers->has('Retry-After') ? ['Retry-After' => $response->headers->get('Retry-After')] : [];
                $response = response()->json(['error' => 'resend_webhook_rejected'], $status >= 500 ? 503 : $status, $headers);
            }
            if (request()->is('api/availability', 'api/availability/*') && $response->getStatusCode() >= 400) {
                $status = $response->getStatusCode();
                $code = match ($status) { 404 => 'not_found', 405 => 'method_not_allowed', 413 => 'payload_too_large', 429 => 'rate_limited', default => 'availability_unavailable' };
                $response = \App\Http\Middleware\AuthenticateWebsiteAvailability::error($code, $status >= 500 ? 503 : $status);
            }
            if (\App\Http\Middleware\SensitiveApplicationResponses::appliesTo(request())) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Pragma', 'no-cache');
                $response->headers->set('Referrer-Policy', 'no-referrer');
                $response->headers->set('X-Content-Type-Options', 'nosniff');
                if (request()->is('r/*', 'staff', 'staff/*', 'portal/tip', 'portal/tip/*', 'portal/api/tip', 'portal/api/tip/*')) $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            }
            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
