<?php

use Illuminate\Support\Facades\Route;

Route::post('/leads', \App\Http\Controllers\WebsiteLeadController::class)->middleware('throttle:120,1');
Route::post('/leads/draft', \App\Http\Controllers\WebsiteLeadDraftController::class)->middleware('throttle:60,1'); // owner 2026-11-26: unfinished requests

// Signed server-to-server preparation only: no browser access, reservation or provider writes.
Route::middleware(\App\Http\Middleware\AuthenticateWebsiteAvailability::class)->group(function () {
    Route::get('/availability', [\App\Http\Controllers\WebsiteAvailabilityController::class, 'day']);
    Route::post('/availability/check', [\App\Http\Controllers\WebsiteAvailabilityController::class, 'candidates']);
});
