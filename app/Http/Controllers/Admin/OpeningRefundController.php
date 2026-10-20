<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Finance\ReadOpeningRefundCandidates;
use Illuminate\Http\Request;

final class OpeningRefundController extends Controller
{
    public function index(Request $request, int $event, ReadOpeningRefundCandidates $service)
    {
        return response()->json($service->execute($request->user(), $event, $request->query()))->header('Cache-Control', 'no-store, private');
    }
}
