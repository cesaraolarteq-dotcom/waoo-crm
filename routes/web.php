<?php

use App\Http\Controllers\Admin\{CoreController, EventController};
use App\Http\Middleware\RequireOwnerAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
Route::get('/', fn() => response()->json(['application' => 'Waoo CRM', 'stage' => 'Phase 1 backend', 'login' => '/login']))->name('home');
// Claude owns the login and 2FA screens. These session endpoints are usable by a same-origin frontend.
Route::get('/login', fn() => response()->json(['message' => 'Autenticación requerida. UI administrativa pendiente de integración.'], 401))->name('login');
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
    Route::post('/costume-compatibilities', [CoreController::class, 'compatibility']);
    Route::get('/events', [EventController::class, 'index']);
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/events/{event}', [EventController::class, 'show']);
    Route::put('/events/{event}', [EventController::class, 'update']);
    Route::post('/events/{event}/confirm-capacity', [EventController::class, 'confirm']);
    Route::get('/events/{event}/audit', [EventController::class, 'audit']);
});
