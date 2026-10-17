<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\BookingSalesReport;
use Illuminate\Http\Request;

final class BookingSalesReportController extends Controller
{
    public function __invoke(Request $request, BookingSalesReport $service)
    {
        return response()->json($service->execute($request->user(), $request->query()))
            ->header('Cache-Control', 'no-store, private');
    }
}
