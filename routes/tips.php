<?php
use App\Http\Controllers\Tips\TipController;
use App\Http\Middleware\RequireOwnerAdmin;
use Illuminate\Support\Facades\Route;
Route::middleware(['auth',RequireOwnerAdmin::class,'throttle:admin-api'])->prefix('admin/api')->group(function(){
    Route::post('/events/{event}/tip-grants',[TipController::class,'issue'])->whereNumber('event');
    Route::post('/tip-grants/{grant}/revoke',[TipController::class,'revoke'])->whereNumber('grant');
    Route::get('/events/{event}/tips',[TipController::class,'owner'])->whereNumber('event');
});
Route::middleware('throttle:60,1,tip-global:')->group(function(){
    Route::get('/portal/tip',[TipController::class,'page']);
    Route::post('/portal/tip/session',[TipController::class,'session'])->middleware('throttle:15,1,tip-session:');
    Route::delete('/portal/tip/session',[TipController::class,'logout']);
    Route::get('/portal/api/tip',[TipController::class,'read']);
    Route::post('/portal/api/tip/checkout',[TipController::class,'checkout'])->middleware('throttle:15,1,tip-checkout:');
    Route::get('/portal/api/tip/checkouts/{intent}',[TipController::class,'status'])->whereUuid('intent');
});
