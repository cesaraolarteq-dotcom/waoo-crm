<?php

use App\Http\Controllers\Admin\{CoreController, EventController, ResourceBlockController};
use App\Http\Controllers\Auth\TwoFactorEnrollmentController;
use App\Http\Middleware\RequireOwnerAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
Route::redirect('/', '/admin')->name('home');
// GET /login, /two-factor-challenge and /user/confirm-password are Fortify views (FortifyServiceProvider).
Route::middleware(['auth', 'password.confirm'])->get('/seguridad/dos-pasos', TwoFactorEnrollmentController::class)->name('security.two-factor');
Route::get('/session/csrf', fn() => response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store'));
Route::middleware(['auth', RequireOwnerAdmin::class, 'throttle:admin-write'])->prefix('admin/api')->group(function () {
    Route::get('/session', fn(Request $r) => ['user' => $r->user()->only(['id', 'name', 'email', 'role']), 'timezone' => 'America/Chicago']);
    foreach (['customers', 'characters', 'resources', 'variants'] as $entity) {
        Route::get('/' . $entity, [CoreController::class, 'index'])->defaults('entity', $entity);
        Route::post('/' . $entity, [CoreController::class, 'store'])->defaults('entity', $entity);
        Route::put('/' . $entity . '/{id}', [CoreController::class, 'update'])->defaults('entity', $entity)->whereNumber('id');
    }
    Route::post('/variants/{variant}/prices', [CoreController::class, 'price']);
    Route::get('/variants/{variant}/prices', [CoreController::class, 'prices']);
    Route::post('/resources/{resource}/blocks', [CoreController::class, 'block']);
    Route::get('/resources/{resource}/blocks', [ResourceBlockController::class, 'index']);
    Route::post('/resources/{resource}/blocks/{block}/release', [ResourceBlockController::class, 'release'])->whereNumber('block');
    Route::post('/costume-compatibilities', [CoreController::class, 'compatibility']);
    Route::post('/costume-compatibilities/unlink', [CoreController::class, 'unlinkCompatibility']);
    Route::get('/events/{event}/costs', [\App\Http\Controllers\Admin\EventCostController::class, 'summary']);
    Route::get('/events/{event}/cost-snapshots', [\App\Http\Controllers\Admin\EventCostController::class, 'index']);
    Route::post('/events/{event}/cost-snapshots', [\App\Http\Controllers\Admin\EventCostController::class, 'store']);
    Route::get('/zelle-notices', [\App\Http\Controllers\Admin\ZelleNoticeController::class, 'index']);
    Route::post('/events/{event}/zelle-notices/{notice}/review', [\App\Http\Controllers\Admin\ZelleNoticeController::class, 'review'])->whereNumber('notice');
    Route::get('/quick-bookings', [\App\Http\Controllers\Admin\QuickBookingController::class, 'index']);
    Route::post('/quick-bookings', [\App\Http\Controllers\Admin\QuickBookingController::class, 'create']);
    Route::get('/events/{event}/client-details', [\App\Http\Controllers\Admin\ClientDetailsController::class, 'index']);
    Route::post('/events/{event}/client-details/{submission}/approve', [\App\Http\Controllers\Admin\ClientDetailsController::class, 'approve']);
    Route::get('/contract-templates', [\App\Http\Controllers\Admin\DocumentController::class, 'templates']);
    Route::get('/contract-templates/{template}', [\App\Http\Controllers\Admin\DocumentController::class, 'template']);
    Route::post('/contract-templates', [\App\Http\Controllers\Admin\DocumentController::class, 'createTemplate']);
    Route::post('/contract-templates/{template}/activate', [\App\Http\Controllers\Admin\DocumentController::class, 'activate']);
    Route::post('/events/{event}/contracts', [\App\Http\Controllers\Admin\DocumentController::class, 'create']);
    Route::post('/contracts/{contract}/issue', [\App\Http\Controllers\Admin\DocumentController::class, 'issue']);
    Route::get('/contracts/{contract}', [\App\Http\Controllers\Admin\DocumentController::class, 'show']);
    Route::get('/contracts/{contract}/pdf', [\App\Http\Controllers\Admin\DocumentController::class, 'pdf']);
    Route::post('/contracts/{contract}/final-pdf/retry', [\App\Http\Controllers\Admin\DocumentController::class, 'retryFinal']);
    Route::get('/contracts/{contract}/evidence', [\App\Http\Controllers\Admin\DocumentController::class, 'evidence']);
    Route::post('/events/{event}/portal-grants', [\App\Http\Controllers\Admin\DocumentController::class, 'grant']);
    Route::post('/portal-grants/{grant}/revoke', [\App\Http\Controllers\Admin\DocumentController::class, 'revoke']);
    Route::post('/events/{event}/checkout-intents', [\App\Http\Controllers\Admin\CheckoutController::class, 'create']);
    Route::get('/payment-intents/{intent}', [\App\Http\Controllers\Admin\CheckoutController::class, 'show']);
    Route::post('/stripe-webhooks/{receipt}/retry', [\App\Http\Controllers\Admin\CheckoutController::class, 'retryWebhook']);
    Route::get('/stripe-webhooks', [\App\Http\Controllers\Admin\CheckoutController::class, 'webhooks']);
    Route::get('/payment-exceptions', [\App\Http\Controllers\Admin\FinanceController::class, 'exceptions']);
    Route::post('/events/{event}/confirm-paid-capacity', [\App\Http\Controllers\Admin\FinanceController::class, 'confirm']);
    Route::post('/events/{event}/financial-revisions', [\App\Http\Controllers\Admin\FinanceController::class, 'revision']);
    Route::get('/events/{event}/finance', [\App\Http\Controllers\Admin\FinanceController::class, 'summary']);
    Route::post('/events/{event}/payments/zelle', [\App\Http\Controllers\Admin\FinanceController::class, 'zelle']);
    Route::get('/events/{event}/payments', [\App\Http\Controllers\Admin\FinanceController::class, 'payments']);
    Route::get('/events', [EventController::class, 'index']);
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/events/{event}', [EventController::class, 'show']);
    Route::put('/events/{event}', [EventController::class, 'update']);
    Route::post('/events/{event}/confirm-capacity', [EventController::class, 'confirm']);
    Route::get('/events/{event}/audit', [EventController::class, 'audit']);
});

// Client portal HTML (Claude); data and writes go through Codex's /portal/api endpoints.
Route::get('/portal', [\App\Http\Controllers\Portal\PortalPageController::class, 'show']);
Route::get('/portal/payment-return', [\App\Http\Controllers\Portal\PortalPageController::class, 'paymentReturn']);
Route::post('/portal/api/session', [\App\Http\Controllers\Portal\PortalController::class, 'session'])->middleware('throttle:30,1');
Route::middleware([\App\Http\Middleware\AuthenticatePortal::class, 'throttle:60,1'])->prefix('portal/api')->group(function () {
    Route::get('/zelle-notices', [\App\Http\Controllers\Portal\ZelleNoticeController::class, 'index']);
    Route::post('/zelle-notices', [\App\Http\Controllers\Portal\ZelleNoticeController::class, 'store']);
    Route::post('/details', [\App\Http\Controllers\Portal\ClientDetailsController::class, 'store']);
    Route::post('/checkout-intents', [\App\Http\Controllers\Portal\CheckoutController::class, 'create']);
    Route::get('/payment-intents/{intent}', [\App\Http\Controllers\Portal\CheckoutController::class, 'show']);
    Route::get('/event', [\App\Http\Controllers\Portal\PortalController::class, 'event']);
    Route::get('/contracts/{contract}', [\App\Http\Controllers\Portal\PortalController::class, 'show']);
    Route::get('/contracts/{contract}/pdf', [\App\Http\Controllers\Portal\PortalController::class, 'pdf']);
    Route::post('/contracts/{contract}/sign', [\App\Http\Controllers\Portal\PortalController::class, 'sign']);
    Route::get('/contracts/{contract}/signature', [\App\Http\Controllers\Portal\PortalController::class, 'signature']);
});

Route::post('/webhooks/stripe', \App\Http\Controllers\Webhooks\StripeWebhookController::class)->middleware('throttle:120,1');
// Design preview of the client portal with fictitious data; the controller returns 404 outside local/testing.
Route::match(['get', 'post'], '/dev/portal/{step?}', \App\Http\Controllers\Portal\PortalPreviewController::class)->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
