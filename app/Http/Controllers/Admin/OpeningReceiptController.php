<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Services\Finance\{AllocateOpeningReceipt, ReadOpeningReceiptCandidates};
use Illuminate\Http\Request;
final class OpeningReceiptController extends Controller
{
    public function index(Request $r, int $event, ReadOpeningReceiptCandidates $service)
    { return response()->json($service->execute($r->user(), $event, $r->query()))->header('Cache-Control', 'no-store, private'); }
    public function allocate(Request $r, int $event, int $ledger, AllocateOpeningReceipt $service)
    { return response()->json($service->execute($r->user(), $event, $ledger, $r->all(), (string)$r->header('Idempotency-Key')))->header('Cache-Control', 'no-store, private'); }
}
