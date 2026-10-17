<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\OperationalReport;
use Illuminate\Http\Request;

final class OperationalReportController extends Controller
{
    public function __invoke(Request $request, OperationalReport $service)
    {
        return response()->json($service->execute($request->user(), $request->query()))
            ->header('Cache-Control', 'no-store, private');
    }
}
