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
        // Preserve raw provider bodies and authenticate before decoding JSON.
        $middleware->trimStrings(except: [fn (Request $r) => $r->is('webhooks/postmark/bounces')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $r) => $r->is('webhooks/postmark/bounces')]);
        $middleware->append(\App\Http\Middleware\SensitiveApplicationResponses::class);
        $middleware->validateCsrfTokens(except: ['webhooks/stripe']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token']);
        // Denials/validation/CSRF failures need the same privacy headers as successful portal responses.
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response) {
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
