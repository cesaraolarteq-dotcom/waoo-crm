<?php

use Illuminate\Support\Facades\Route;

Route::post('/leads', \App\Http\Controllers\WebsiteLeadController::class)->middleware('throttle:120,1');
