<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SensitiveApplicationResponses::class);
        $middleware->validateCsrfTokens(except: ['webhooks/stripe']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Denials/validation/CSRF failures need the same privacy headers as successful portal responses.
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response) {
            if (\App\Http\Middleware\SensitiveApplicationResponses::appliesTo(request())) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Referrer-Policy', 'no-referrer');
                $response->headers->set('X-Content-Type-Options', 'nosniff');
            }
            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
