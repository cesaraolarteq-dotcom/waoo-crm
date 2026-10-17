<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\{ReadOwnerReport, RenderOwnerReportEmail};
use Illuminate\Http\Request;

final class OwnerReportReadController extends Controller
{
    public function index(Request $request, ReadOwnerReport $service)
    {
        return response()->json($service->execute($request->user(), $request->query()))->header('Cache-Control', 'no-store, private');
    }
    public function preview(Request $request, int $snapshot, RenderOwnerReportEmail $service)
    {
        return response()->json($service->execute($request->user(), $snapshot))->header('Cache-Control', 'no-store, private');
    }
}
