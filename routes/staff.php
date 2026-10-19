<?php
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Staff\StaffPortalController;
use App\Http\Middleware\RequireOwnerAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth',RequireOwnerAdmin::class,'throttle:admin-write'])->prefix('admin/api')->group(function () {
    Route::get('/staff/links',[StaffController::class,'index']);
    Route::post('/staff/identities',[StaffController::class,'identities']);
    Route::post('/staff/{staff}/grants',[StaffController::class,'issue'])->whereNumber('staff');
    Route::post('/staff/grants/{grant}/reveal',[StaffController::class,'reveal'])->whereNumber('grant');
    Route::post('/staff/grants/{grant}/rotate',[StaffController::class,'rotate'])->whereNumber('grant');
    Route::post('/staff/grants/{grant}/revoke',[StaffController::class,'revoke'])->whereNumber('grant');
    Route::get('/events/{event}/staff-instructions',[StaffController::class,'readInstructions'])->whereNumber('event');
    Route::post('/events/{event}/staff-instructions',[StaffController::class,'instructions'])->whereNumber('event');
});
Route::middleware('throttle:60,1')->prefix('staff')->group(function () {
    Route::get('/',[StaffPortalController::class,'page']);
    Route::post('/session',[StaffPortalController::class,'session'])->middleware('throttle:15,1');
    Route::delete('/session',[StaffPortalController::class,'logout']);
    Route::get('/api/week',[StaffPortalController::class,'week']);
});
