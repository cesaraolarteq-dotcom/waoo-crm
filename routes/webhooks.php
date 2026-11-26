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

// Owner 2026-11-26: HoneyBook → CRM every 12 hours until the switch (cloud job, bearer secret).
Route::post('/sync/honeybook', \App\Http\Controllers\Webhooks\HoneyBookSyncController::class)
    ->middleware('throttle:10,1');

// Owner 2026-11-26: WhatsApp 8830 via Waoo's own Meta app (Cloud API). Signed by Meta (X-Hub-Signature-256).
Route::get('/webhooks/whatsapp', [\App\Http\Controllers\Webhooks\WhatsAppWebhookController::class, 'verify'])->middleware('throttle:30,1');
Route::post('/webhooks/whatsapp', [\App\Http\Controllers\Webhooks\WhatsAppWebhookController::class, 'receive'])->middleware('throttle:240,1');

// Owner 2026-11-26 (point 5): Google Ads script → daily spend in, booked prospects out (offline conversions).
Route::post('/sync/ads/google', [\App\Http\Controllers\Webhooks\AdsSyncController::class, 'spend'])->middleware('throttle:20,1');
Route::post('/sync/ads/google/ack', [\App\Http\Controllers\Webhooks\AdsSyncController::class, 'ack'])->middleware('throttle:20,1');
