<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\{Event,FinancialRevision};
use App\Services\Billing\SaveEventBillingCompany;
use App\Services\Documents\PrintRenderer;
use App\Services\Finance\Sandbox;
use App\Services\Records\RecordActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class EventBillingController extends Controller
{
    public function save(Request $request,int $event,SaveEventBillingCompany $service) {
        return response()->json($service->execute($request->user(),$event,$request->all(),(string)$request->header('Idempotency-Key')))->header('Cache-Control','no-store, private');
    }
    public function preview(Request $request,int $event,PrintRenderer $renderer) {
        RecordActor::authorize($request->user());Sandbox::require('sandbox_company');Sandbox::require();
        \App\Services\Portal\PortalContext::requireTransport($request);
        $html=DB::transaction(function()use($event){
            $e=Event::whereKey($event)->sharedLock()->firstOrFail();$e->load('customer');
            $finance=FinancialRevision::where('event_id',$event)->orderByDesc('revision')->firstOrFail();
            return view('pdf.billing-preview',['event'=>$e,'finance'=>$finance,'company'=>$e->billing_company,'person'=>\App\Services\Billing\BillingCompany::person($e)])->render();
        });
        return response($renderer->pdf($html))->header('Content-Type','application/pdf')->header('Cache-Control','no-store, private')->header('Content-Disposition','attachment; filename="billing-draft.pdf"');
    }
}
