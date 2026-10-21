<?php

use App\Http\Controllers\Admin\{CoreController, EventController, ResourceBlockController};
use App\Http\Controllers\Auth\TwoFactorEnrollmentController;
use App\Http\Middleware\RequireOwnerAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
require __DIR__.'/staff.php';
require __DIR__.'/tips.php';
Route::redirect('/', '/admin')->name('home');
// GET /login, /two-factor-challenge and /user/confirm-password are Fortify views (FortifyServiceProvider).
Route::middleware(['auth', 'password.confirm'])->get('/seguridad/dos-pasos', TwoFactorEnrollmentController::class)->name('security.two-factor');
Route::get('/session/csrf', fn() => response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store'));
// Owner 2026-10-21: Google Calendar OAuth (owner with confirmed 2FA only).
Route::middleware(['auth', RequireOwnerAdmin::class, 'throttle:20,1'])->prefix('admin/google')->group(function () {
    Route::get('/connect', [\App\Http\Controllers\Admin\GoogleCalendarController::class, 'connect']);
    Route::get('/callback', [\App\Http\Controllers\Admin\GoogleCalendarController::class, 'callback']);
});
Route::middleware(['auth', RequireOwnerAdmin::class, 'throttle:admin-write'])->prefix('admin/api')->group(function () {
    Route::get('/cutover/honeybook', [\App\Http\Controllers\Admin\HoneyBookCutoverController::class, 'index']);
    Route::get('/cutover/honeybook/events/{event}', [\App\Http\Controllers\Admin\HoneyBookCutoverController::class, 'show'])->whereNumber('event');
    Route::post('/cutover/honeybook/events/{event}', [\App\Http\Controllers\Admin\HoneyBookCutoverController::class, 'review'])->whereNumber('event');
    Route::post('/cutover/reconciliations', [\App\Http\Controllers\Admin\HoneyBookCutoverController::class, 'prepare']);
    Route::get('/mail/occurrences', [\App\Http\Controllers\Admin\MailController::class, 'occurrences']);
    Route::get('/mail/settings', [\App\Http\Controllers\Admin\MailController::class, 'settings']);
    Route::get('/messages/warnings', [\App\Http\Controllers\Admin\MailController::class, 'warnings']);
    Route::get('/events/{event}/messages', [\App\Http\Controllers\Admin\MailController::class, 'messages'])->whereNumber('event');
    Route::post('/events/{event}/mail/reminders', [\App\Http\Controllers\Admin\MailController::class, 'plan'])->whereNumber('event');
    Route::patch('/events/{event}/mail/policy', [\App\Http\Controllers\Admin\MailController::class, 'pause'])->whereNumber('event');
    Route::get('/imports/honeybook/{batch}/rows/{row}/mapping', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'mapping'])->whereNumber(['batch','row']);
    Route::get('/imports/honeybook/{batch}/rows/{row}/diff', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'diff'])->whereNumber(['batch','row']);
    Route::post('/imports/honeybook/{batch}/rows/{row}/commit', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'commit'])->whereNumber(['batch','row']);
    Route::get('/imports/honeybook/{batch}/coverage', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'coverage'])->whereNumber('batch');
    Route::post('/imports/honeybook/{batch}/finalize', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'finalize'])->whereNumber('batch');
    Route::get('/imported-events/{event}', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'imported'])->whereNumber('event');
    Route::post('/imported-events/{event}/confirm', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'confirm'])->whereNumber('event');
    Route::post('/imports/honeybook/future', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'future']);
    Route::get('/imports/honeybook', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'index']);
    Route::post('/imports/honeybook', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'stage']);
    Route::get('/imports/honeybook/{batch}', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'show'])->whereNumber('batch');
    Route::get('/imports/honeybook/{batch}/rows', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'rows'])->whereNumber('batch');
    Route::post('/imports/honeybook/{batch}/profile', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'profile'])->whereNumber('batch');
    Route::post('/imports/honeybook/{batch}/discard', [\App\Http\Controllers\Admin\HoneyBookImportController::class, 'discard'])->whereNumber('batch');
    Route::get('/quotes', [\App\Http\Controllers\Admin\QuoteController::class, 'index']);
    Route::post('/quotes', [\App\Http\Controllers\Admin\QuoteController::class, 'create']);
    Route::post('/quotes/{quote}/send', [\App\Http\Controllers\Admin\QuoteController::class, 'send'])->whereNumber('quote');
    Route::post('/quotes/{quote}/convert', [\App\Http\Controllers\Admin\QuoteController::class, 'convert'])->whereNumber('quote');
    Route::get('/quotes/{quote}', [\App\Http\Controllers\Admin\QuoteController::class, 'show'])->whereNumber('quote');
    Route::post('/quotes/{quote}/revisions', [\App\Http\Controllers\Admin\QuoteController::class, 'revise'])->whereNumber('quote');
    Route::post('/travel-fee-quotes', [\App\Http\Controllers\Admin\TravelFeeController::class, 'store']);
    Route::get('/events/{event}/travel-fee-evidence', [\App\Http\Controllers\Admin\TravelFeeController::class, 'evidence'])->whereNumber('event');
    Route::get('/leads', [\App\Http\Controllers\Admin\LeadController::class, 'index']);
    Route::get('/leads/{lead}', [\App\Http\Controllers\Admin\LeadController::class, 'show'])->whereNumber('lead');
    Route::patch('/leads/{lead}', [\App\Http\Controllers\Admin\LeadController::class, 'update'])->whereNumber('lead');
    Route::post('/leads/{lead}/quick-book', [\App\Http\Controllers\Admin\LeadController::class, 'convert'])->whereNumber('lead');
    Route::get('/reports/owner', [\App\Http\Controllers\Admin\OwnerReportReadController::class, 'index']);
    Route::get('/reports/snapshots/{snapshot}/email-preview', [\App\Http\Controllers\Admin\OwnerReportReadController::class, 'preview'])->whereNumber('snapshot');
    Route::post('/reports/snapshots', [\App\Http\Controllers\Admin\OwnerReportSnapshotController::class, 'store']);
    Route::get('/reports/snapshots/{snapshot}', [\App\Http\Controllers\Admin\OwnerReportSnapshotController::class, 'show'])->whereNumber('snapshot');
    Route::get('/reports/bookings', \App\Http\Controllers\Admin\BookingSalesReportController::class);
    Route::get('/reports/weekend', \App\Http\Controllers\Admin\WeekendForecastController::class);
    Route::get('/reports/operations', \App\Http\Controllers\Admin\OperationalReportController::class);
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
    Route::get('/events/{event}/costume-hold', [\App\Http\Controllers\Admin\QuickCostumeHoldController::class, 'show'])->whereNumber('event');
    Route::post('/events/{event}/costume-hold', [\App\Http\Controllers\Admin\QuickCostumeHoldController::class, 'attempt'])->whereNumber('event');
    Route::post('/events/{event}/costume-hold/release', [\App\Http\Controllers\Admin\QuickCostumeHoldController::class, 'release'])->whereNumber('event');
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
    Route::post('/events/{event}/portal-grants/{grant}/reveal-link', [\App\Http\Controllers\Admin\DocumentController::class, 'revealLink'])->whereNumber(['event', 'grant']);
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
Route::get('/r/{code}', \App\Http\Controllers\Portal\ShortPortalLinkController::class)->middleware('throttle:20,1');
Route::get('/portal', [\App\Http\Controllers\Portal\PortalPageController::class, 'show']);
Route::get('/portal/payment-return', [\App\Http\Controllers\Portal\PortalPageController::class, 'paymentReturn']);
Route::get('/portal/quote', [\App\Http\Controllers\Portal\PortalPageController::class, 'quote']);
Route::post('/portal/api/session', [\App\Http\Controllers\Portal\PortalController::class, 'session'])->middleware('throttle:30,1');
Route::middleware([\App\Http\Middleware\AuthenticatePortal::class, 'throttle:60,1'])->prefix('portal/api')->group(function () {
    Route::get('/quote', [\App\Http\Controllers\Portal\QuoteController::class, 'show']);
    Route::get('/quote/pdf', [\App\Http\Controllers\Portal\QuoteController::class, 'pdf']);
    Route::post('/quote/accept', [\App\Http\Controllers\Portal\QuoteController::class, 'accept']);
    Route::post('/quote/reject', [\App\Http\Controllers\Portal\QuoteController::class, 'reject']);
    Route::get('/quick-payment', [\App\Http\Controllers\Portal\QuickPaymentController::class, 'show']);
    Route::post('/quick-payment/checkout/close', [\App\Http\Controllers\Portal\QuickPaymentController::class, 'close']);
    Route::post('/quick-payment/checkout', [\App\Http\Controllers\Portal\QuickPaymentController::class, 'checkout']);
    Route::post('/quick-payment/zelle', [\App\Http\Controllers\Portal\QuickPaymentController::class, 'zelle']);
    Route::get('/zelle-notices', [\App\Http\Controllers\Portal\ZelleNoticeController::class, 'index']);
    Route::post('/zelle-notices', [\App\Http\Controllers\Portal\ZelleNoticeController::class, 'store']);
    Route::post('/details', [\App\Http\Controllers\Portal\ClientDetailsController::class, 'store']);
    Route::post('/details/contract', [\App\Http\Controllers\Portal\ClientDetailsController::class, 'prepare']);
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
Route::get('/dev/mail/{key?}', \App\Http\Controllers\MailPreviewController::class);
// Design preview of the REPORT-A Monday owner email (sample data); 404 outside local/testing.
Route::get('/dev/mail-owner-report', \App\Http\Controllers\OwnerReportPreviewController::class);
// Design preview of the STAFF-01 read-only staff page (my week); 404 outside local/testing.
Route::get('/dev/staff/{screen?}', \App\Http\Controllers\Staff\StaffPreviewController::class);
Route::match(['get', 'post'], '/dev/portal/{step?}', \App\Http\Controllers\Portal\PortalPreviewController::class)->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
