<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\History\{CommitHistoricalRow, DiscardHistoricalImport, ReadCustomerHistory, ReadHistoricalImport, ReadHistoricalRow, StageHistoricalImport};
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
    public function commit(Request $r, int $batch, int $row, CommitHistoricalRow $service)
    {
        return response()->json($service->execute($r->user(), $batch, $row, $r->all(), (string)$r->header('Idempotency-Key')))->header('Cache-Control', 'no-store, private');
    }
    public function bulk(Request $r, \App\Services\History\ImportSafeHistoricalRows $service)
    {
        return response()->json($service->execute($r->user(), $r->route('batch'), $r->all(), (string)$r->header('Idempotency-Key')));
    }
    public function exclude(Request $r, int $batch, int $row, \App\Services\History\ExcludeHistoricalRow $service)
    {
        return response()->json($service->execute($r->user(), $batch, $row, $r->all(), (string)$r->header('Idempotency-Key')));
    }
    public function close(Request $r, int $batch, \App\Services\History\CloseHistoricalImport $service)
    {
        return response()->json($service->execute($r->user(), $batch, $r->all(), (string)$r->header('Idempotency-Key')));
    }
    public function facts(Request $r,int $event,\App\Services\History\SaveHoneyBookEventFacts $service) {
        return response()->json($service->execute($r->user(),$event,$r->all(),(string)$r->header('Idempotency-Key')));
    }
    public function readFacts(Request $r,int $event,\App\Services\History\ReadHoneyBookEventFacts $service) {
        return response()->json($service->execute($r->user(),$event));
    }
    public function original(Request $r,\App\Services\History\PreserveHoneyBookOriginal $service) {
        $d=ImportInput::input($r->all(),['file'=>'required|file','project_id'=>'required|string|max:200','kind'=>'required|in:contract,invoice','source_reference'=>'required|string|max:1000']);
        $file=$d['file'];unset($d['file']);return response()->json($service->execute($r->user(),$file,$d,(string)$r->header('Idempotency-Key')),201);
    }
    public function download(Request $r,int $original,\App\Services\History\ReadHoneyBookOriginal $service) {
        $result=$service->execute($r->user(),$original);
        return response($result['bytes'])->header('Content-Type','application/pdf')->header('Content-Disposition','attachment; filename="honeybook-original.pdf"')->header('Content-Security-Policy',"default-src 'none'; sandbox");
    }
    public function verification(Request $r,int $batch,\App\Services\History\VerifyHistoricalImport $service) {return response()->json($service->execute($r->user(),$batch));}
    public function report(Request $r,\App\Services\History\ReadHoneyBookArchiveReport $service) {return response()->json($service->execute($r->user()));}
    public function customer(Request $r, int $customer, ReadCustomerHistory $service)
    {
        return response()->json($service->execute($r->user(), $customer, $r->query()))->header('Cache-Control', 'no-store, private');
    }
}
