<?php

use App\Http\Middleware\RequireOwnerAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['application' => 'Waoo CRM', 'stage' => 'Phase 1 bootstrap']))->name('home');
// Claude owns login, enrollment, challenge and admin screens (Phase 1 step 4).
Route::get('/login', fn () => response()->json(['message' => 'Autenticación requerida.'], 401))->name('login');
Route::get('/session/csrf', fn () => response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store'));
Route::middleware(['auth', RequireOwnerAdmin::class, 'throttle:admin-write'])->get('/admin/api/session', fn (Request $request) => [
    'user' => $request->user()->only(['id', 'name', 'email', 'role']),
    'timezone' => 'America/Chicago',
]);
