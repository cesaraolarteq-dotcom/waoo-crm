<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Event, LedgerEntry};
use App\Services\Finance\{CreateFinancialRevision, FinanceSummary, RecordZellePayment, Sandbox};
use Illuminate\Http\Request;

final class FinanceController extends Controller
{
    public function revision(Request $request, Event $event, CreateFinancialRevision $service)
    {
        return response()->json($service->execute($request->user(), $event->id, $request->all(), (string) $request->header('Idempotency-Key')), 201)->header('Cache-Control', 'no-store, private');
    }
    public function summary(Event $event, FinanceSummary $service)
    {
        Sandbox::require();
        return response()->json($service->forEvent($event))->header('Cache-Control', 'no-store, private');
    }
    public function zelle(Request $request, Event $event, RecordZellePayment $service, FinanceSummary $summary)
    {
        $entry = $service->execute($request->user(), $event->id, $request->all(), (string) $request->header('Idempotency-Key'));
        return response()->json(['payment' => $entry, 'finance' => $summary->forEvent($event->fresh())], 201)->header('Cache-Control', 'no-store, private');
    }
    public function payments(Event $event)
    {
        Sandbox::require();
        return response()->json(LedgerEntry::where('event_id', $event->id)->where('environment', 'test')->orderByDesc('received_at')->paginate(50))->header('Cache-Control', 'no-store, private');
    }
    public function confirm(Request $request, Event $event, \App\Services\Finance\ConfirmPaidCapacity $service, FinanceSummary $summary)
    {
        $saved = $service->execute($request->user(), $event->id, $request->all());
        return response()->json(['capacity_status' => $saved->status, 'finance' => $summary->forEvent($saved), 'contract_status' => $summary->forEvent($saved)['contract_status']])->header('Cache-Control', 'no-store, private');
    }
    public function exceptions()
    {
        Sandbox::require();
        return response()->json(\Illuminate\Support\Facades\DB::table('payment_exceptions')->where('state', 'open')->orderByDesc('id')->paginate(50))->header('Cache-Control', 'no-store, private');
    }

}
