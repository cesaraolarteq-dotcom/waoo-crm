<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\History\{DiscardHistoricalImport, ReadHistoricalImport, ReadHistoricalRow, StageHistoricalImport};
use App\Services\Imports\ImportInput;
use Illuminate\Http\Request;

final class HistoricalImportController extends Controller
{
    public function stage(Request $r, StageHistoricalImport $service)
    {
        $d = ImportInput::input($r->all(), ['file' => 'required|file']);
        return response()->json($service->execute($r->user(), $d['file'], (string)$r->header('Idempotency-Key')), 201)->header('Cache-Control', 'no-store, private');
    }
    public function show(Request $r, int $batch, ReadHistoricalImport $service)
    {
        return response()->json($service->execute($r->user(), $batch, $r->query()))->header('Cache-Control', 'no-store, private');
    }
    public function row(Request $r, int $batch, int $row, ReadHistoricalRow $service)
    {
        return response()->json($service->execute($r->user(), $batch, $row))->header('Cache-Control', 'no-store, private');
    }
    public function discard(Request $r, int $batch, DiscardHistoricalImport $service)
    {
        return response()->json($service->execute($r->user(), $batch, $r->all(), (string)$r->header('Idempotency-Key')))->header('Cache-Control', 'no-store, private');
    }
}
