<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Imports\{PrepareCutoverOpeningAgreements, ReadCutoverOpeningAgreements};
use Illuminate\Http\Request;

final class CutoverOpeningController extends Controller
{
    public function prepare(Request $r, PrepareCutoverOpeningAgreements $service)
    {
        return response()->json($service->execute($r->user(), $r->all(), (string)$r->header('Idempotency-Key')))->header('Cache-Control', 'no-store, private');
    }
    public function show(Request $r, int $set, ReadCutoverOpeningAgreements $service)
    {
        return response()->json($service->execute($r->user(), $set))->header('Cache-Control', 'no-store, private');
    }
}
