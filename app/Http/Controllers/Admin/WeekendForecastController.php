<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\WeekendForecast;
use Illuminate\Http\Request;

final class WeekendForecastController extends Controller
{
    public function __invoke(Request $request, WeekendForecast $service)
    {
        return response()->json($service->execute($request->user(), $request->query()))
            ->header('Cache-Control', 'no-store, private');
    }
}
