<?php

use Illuminate\Support\Facades\Route;

// Stateless: no session, cookies or CSRF exception; native provider authentication is mandatory.
Route::post('/webhooks/postmark/bounces', \App\Http\Controllers\Webhooks\PostmarkBounceController::class)
    ->middleware('throttle:120,1');

Route::post('/webhooks/resend', \App\Http\Controllers\Webhooks\ResendWebhookController::class)
    ->middleware('throttle:120,1');

// Owner 2026-10-28: Twilio texts. Signed by Twilio (X-Twilio-Signature); nothing is accepted without a valid signature.
Route::post('/webhooks/twilio/inbound', [\App\Http\Controllers\Webhooks\TwilioWebhookController::class, 'inbound'])
    ->middleware('throttle:120,1');

Route::post('/webhooks/twilio/status', [\App\Http\Controllers\Webhooks\TwilioWebhookController::class, 'status'])
    ->middleware('throttle:240,1');
