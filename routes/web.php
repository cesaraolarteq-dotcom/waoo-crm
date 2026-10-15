<?php

use App\Http\Controllers\Auth\TwoFactorEnrollmentController;
use App\Http\Middleware\RequireOwnerAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin')->name('home');
// GET /login, /two-factor-challenge and /user/confirm-password are Fortify views (FortifyServiceProvider).
Route::middleware(['auth', 'password.confirm'])->get('/seguridad/dos-pasos', TwoFactorEnrollmentController::class)->name('security.two-factor');
Route::get('/session/csrf', fn () => response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store'));
Route::middleware(['auth', RequireOwnerAdmin::class, 'throttle:admin-write'])->get('/admin/api/session', fn (Request $request) => [
    'user' => $request->user()->only(['id', 'name', 'email', 'role']),
    'timezone' => 'America/Chicago',
]);
