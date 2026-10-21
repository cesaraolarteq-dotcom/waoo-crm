<?php
namespace App\Http\Controllers\Portal;
use App\Http\Controllers\Controller;
use App\Services\Booking\{QuickPaymentProtocol,SubmitQuickPaymentZelle,ReadQuickPayment};
use Illuminate\Http\Request;
final class QuickPaymentController extends Controller
{
    public function show(Request $r,ReadQuickPayment $service){return response()->json($service->execute($r->attributes->get('portal_context')))->header('Cache-Control','no-store, private');}
    public function close(Request $r,\App\Services\Booking\CloseQuickPaymentCheckout $service){return response()->json($service->execute($r->attributes->get('portal_context'),$r->all(),(string)$r->header('Idempotency-Key')))->header('Cache-Control','no-store, private');}
    public function checkout(Request $r,\App\Services\Booking\QuickPaymentCheckout $service){return response()->json($service->execute($r->attributes->get('portal_context'),$r->all(),(string)$r->header('Idempotency-Key')))->header('Cache-Control','no-store, private');}
    public function zelle(Request $r,SubmitQuickPaymentZelle $service){$n=$service->execute($r->attributes->get('portal_context'),$r->all(),(string)$r->header('Idempotency-Key'));return response()->json(['notice_id'=>$n->id,'state'=>'zelle_pending','receipt_recorded'=>false],201)->header('Cache-Control','no-store, private');}
}
