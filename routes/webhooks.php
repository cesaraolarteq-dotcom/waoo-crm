<?php

use Illuminate\Support\Facades\Route;

// Stateless: no session, cookies or CSRF exception; native provider authentication is mandatory.
Route::post('/webhooks/postmark/bounces', \App\Http\Controllers\Webhooks\PostmarkBounceController::class)
    ->middleware('throttle:120,1');

Route::post('/webhooks/resend', \App\Http\Controllers\Webhooks\ResendWebhookController::class)
    ->middleware('throttle:120,1');
