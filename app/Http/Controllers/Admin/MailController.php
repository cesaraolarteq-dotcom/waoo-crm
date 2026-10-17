<?php
namespace App\Http\Controllers\Admin;
use App\Services\Mail\{PlanEventMail,PauseEventMail,ReadOutboundMail};
use Illuminate\Http\Request;
class MailController extends \App\Http\Controllers\Controller
{
    public function settings(Request $r,ReadOutboundMail $read){return $read->settings($r->user());}
    public function messages(Request $r,int $event,ReadOutboundMail $read){return $read->forEvent($r->user(),$event,$r->query());}
    public function warnings(Request $r,ReadOutboundMail $read){return $read->warnings($r->user(),$r->query());}
    public function plan(Request $r,int $event,PlanEventMail $service){return response()->json($service->execute($r->user(),$event,$r->all(),(string)$r->header('Idempotency-Key')),201);}
    public function pause(Request $r,int $event,PauseEventMail $service){return $service->execute($r->user(),$event,$r->all(),(string)$r->header('Idempotency-Key'));}
}
