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
