<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', fn () => response('Waoo CRM test environment (Phase 0). Nothing to see here.', 200)->header('Content-Type', 'text/plain'));

// P2: private file, only through a temporary signed URL.
Route::get('/probe/pdf', function () {
    abort_unless(Storage::disk('local')->exists('private/probe.pdf'), 404);
    return response()->file(Storage::disk('local')->path('private/probe.pdf'), ['Content-Type' => 'application/pdf']);
})->name('probe.pdf')->middleware('signed');

// P3: Stripe webhook (raw body + signature), stored once per event id.
Route::post('/stripe/webhook', function (Request $request) {
    $secret = config('services.stripe.webhook_secret') ?: env('STRIPE_WEBHOOK_SECRET');
    abort_if(empty($secret), 503, 'Webhook secret not configured');
    try {
        $event = \Stripe\Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
    } catch (\Throwable $e) {
        return response('invalid signature', 400);
    }
    $inserted = DB::table('provider_events')->insertOrIgnore([
        'provider' => 'stripe', 'event_id' => $event->id, 'type' => $event->type,
        'payload' => $request->getContent(), 'received_at' => now(),
    ]);
    return response($inserted ? 'stored' : 'duplicate ignored', 200);
});
