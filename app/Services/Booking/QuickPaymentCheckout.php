<?php
namespace App\Services\Booking;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event,PaymentIntent,QuickPaymentAttempt,QuickStripeCheckoutRequest,QuickStripeLease};
use App\Services\{Audit};
use App\Services\Finance\{MutationKey,StripeGateway,QuickStripeGateway};
use App\Services\Portal\PortalContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class QuickPaymentCheckout
{
    public function execute(PortalContext $client,array $input,string $key):array
    {
        QuickPaymentProtocol::gate();\App\Services\Records\RecordInput::validate(['key'=>$key],['key'=>'required|uuid']);RecordWriteRejected::unless(DB::transactionLevel()===0,409,'No se pudo iniciar el pago.');
        $d=QuickPaymentProtocol::input($input,['deposit_percent'=>'required|integer|in:50,100']);$d['deposit_percent']=(int)$d['deposit_percent'];
        $scope="portal:{$client->grantId}:quick-checkout";$hash=MutationKey::hash($d);
        // Authenticate the caller before any provider request. No DB locks during network calls.
        DB::transaction(fn()=>QuickPaymentProtocol::portal($client),3);
        $gateway=app(StripeGateway::class);RecordWriteRejected::unless($gateway instanceof QuickStripeGateway,409,'Los métodos de Stripe aún no están verificados.');
        $methods=config('waoo.quick_payment_methods');
        RecordWriteRejected::unless(is_array($methods)&&$methods!==[]&&array_is_list($methods)&&count($methods)===count(array_unique($methods))&&array_diff($methods,['card','klarna'])===[],409,'Revisa los métodos de pago.');
        sort($methods);
        try{$evidence=$gateway->verifyMethods($methods);}
        catch(\Illuminate\Auth\Access\AuthorizationException $error){throw $error;}
        catch(\Throwable){throw new RecordWriteRejected(503,'No pudimos verificar los métodos de Stripe. Puedes elegir Zelle o reintentar. / We could not verify Stripe methods. Choose Zelle or retry.');}
        RecordWriteRejected::unless($evidence===['account'=>config('waoo.stripe_test_account'),'methods'=>$methods],409,'La cuenta o los métodos de Stripe no coinciden.');
        [$request,$intent]=DB::transaction(function()use($client,$d,$key,$scope,$hash,$evidence){
            [$b,$e,$grant]=QuickPaymentProtocol::portal($client);QuickPaymentProtocol::submission($b,$e);
            if($r=MutationKey::find($scope,$key,$hash,true)){
                $request=QuickStripeCheckoutRequest::findOrFail($r->result_id);$a=QuickPaymentAttempt::findOrFail($request->id);
                RecordWriteRejected::unless($a->quick_payment_booking_id===$b->id&&$a->portal_grant_id===$grant->id,403,'La solicitud no corresponde.');
                self::verify($request);return [$request,PaymentIntent::findOrFail($a->payment_intent_id)];
            }
            QuickPaymentProtocol::pin($b,$d);
            RecordWriteRejected::unless($b->state==='awaiting_payment'&&$b->expires_at->isFuture()&&!QuickPaymentProtocol::pendingNotice($b),409,'El apartado cambió o venció.');
            $lease=QuickStripeLease::whereKey($b->quick_stripe_lease_id)->lockForUpdate()->firstOrFail();$source=QuickStripeLeases::verify($lease,$e,false);
            QuickStripeLeases::lockInventory($source);$source=QuickStripeLeases::verify($lease,$e);
            RecordWriteRejected::unless(!PaymentIntent::where('event_id',$e->id)->whereNotIn('status',['expired','failed','cancelled'])->lockForUpdate()->first(),409,'Ya existe un intento de pago. Revisa el original antes de abrir otro.');
            RecordWriteRejected::unless(!QuickStripeCheckoutRequest::where('quick_stripe_lease_id',$lease->id)->whereNotIn('id',QuickPaymentAttempt::select('id'))->exists(),409,'El apartado tiene una solicitud anterior que requiere revisión.');
            $terms=QuickStripeTerms::freeze($source['terms']['total_cents'],CarbonImmutable::createFromTimestampUTC($source['terms']['created_at']),$d['deposit_percent']);
            $now=CarbonImmutable::now()->startOfSecond();$expires=$terms->checkoutExpiresAt($now);$id=(string)Str::uuid();$intentId=(string)Str::uuid();
            $params=QuickStripeCheckoutRequests::parameters($id,$e->id,$terms->snapshot(),$e->starts_at->setTimezone('America/Chicago')->format('Y-m-d'),\App\Services\Finance\Sandbox::origin(),$evidence['methods'],$expires);
            $p=['schema_version'=>2,'request_id'=>$id,'booking_id'=>$b->id,'agreement_sha256'=>$b->agreement_sha256,'grant_id'=>$grant->id,
                'lease_id'=>$lease->id,'lease_sha256'=>$lease->payload_sha256,'terms'=>$terms->snapshot(),'capabilities'=>$evidence,'created_at'=>$now->timestamp,'provider_key'=>'waoo-quick-test:'.$id,'parameters'=>$params];
            $request=QuickStripeCheckoutRequest::create(['id'=>$id,'quick_stripe_lease_id'=>$lease->id,'event_id'=>$e->id,'customer_id'=>$b->customer_id,'financial_revision_id'=>$b->financial_revision_id,
                'envelope'=>$p,'envelope_sha256'=>MutationKey::hash($p),'created_at'=>$now,'updated_at'=>$now]);
            $t=$terms->snapshot();$intent=PaymentIntent::create(['id'=>$intentId,'event_id'=>$e->id,'financial_revision_id'=>$b->financial_revision_id,'purpose'=>'quick_deposit','status'=>'prepared',
                'collection_slot'=>'event:'.$e->id,'principal_cents'=>$t['principal_cents'],'customer_fee_cents'=>$t['customer_fee_cents'],'payable_cents'=>$t['payable_cents'],'currency'=>'USD','expires_at'=>CarbonImmutable::createFromTimestampUTC($expires)]);
            QuickPaymentAttempt::create(['id'=>$id,'quick_payment_booking_id'=>$b->id,'portal_grant_id'=>$grant->id,'payment_intent_id'=>$intentId,'booking_version'=>$b->version,'request_sha256'=>$request->envelope_sha256]);
            Audit::recordSystem('quick_payment.request_prepared',$request,[],['event_id'=>$e->id,'sha256'=>$request->envelope_sha256]);
            MutationKey::store($scope,$key,$hash,'quick_stripe_checkout_request',$id);return [$request,$intent];
        },3);
        if($intent->status==='paid')return ['state'=>'paid','checkout_url'=>null];
        RecordWriteRejected::unless(!in_array($intent->status,['failed','expired','cancelled','superseded','reconciliation_required'],true),409,'Revisa el intento anterior.');
        // Recheck original authority and deadline even when the request is replayed.
        DB::transaction(function()use($client,$request){[$b]=QuickPaymentProtocol::portal($client);self::verify($request);RecordWriteRejected::unless($b->state==='awaiting_payment'&&$b->expires_at->isFuture()&&!QuickPaymentProtocol::pendingNotice($b)&&$request->envelope['parameters']['expires_at']>now()->timestamp+30,409,'El apartado cambió o venció.');},3);
        $p=self::verify($request);
        RecordWriteRejected::unless($p['capabilities']===$evidence && str_starts_with($p['parameters']['success_url'],\App\Services\Finance\Sandbox::origin().'/portal?'),409,'La configuración de Stripe cambió; revisa el intento original.');
        try{$session=$gateway->createCheckout($p['parameters'],$p['provider_key']);}
        catch(\Illuminate\Auth\Access\AuthorizationException $error){throw $error;}
        catch(\Throwable){throw new RecordWriteRejected(503,'Stripe no respondió. Reintenta el mismo intento; no se ha registrado un pago. / Stripe did not respond. Resume the same attempt; no payment has been recorded.');}
        self::session($session,$request);
        try { return DB::transaction(function()use($client,$request,$intent,$session){
            [$b]=QuickPaymentProtocol::portal($client);$locked=PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            RecordWriteRejected::unless(!$locked->stripe_checkout_session_id||$locked->stripe_checkout_session_id===$session['id'],409,'La sesión cambió.');
            $changes=['stripe_checkout_session_id'=>$session['id']];
            if($locked->status!=='paid'){
                RecordWriteRejected::unless($b->state==='awaiting_payment'&&$b->expires_at->isFuture(),409,'El apartado cambió; el pago requiere revisión.');
                RecordWriteRejected::unless(is_string($session['url']??null)&&preg_match('~^https://checkout\.stripe\.com/[^\s]+$~D',$session['url']),409,'Enlace de Stripe inválido.');
                $changes+=['status'=>'open','checkout_url'=>$session['url']];
            }
            $locked->fill($changes)->save();return ['request_id'=>$request->id,'state'=>$locked->status,'checkout_url'=>$locked->status==='paid'?null:$locked->checkout_url];
        },3); } catch (RecordWriteRejected|\Illuminate\Auth\Access\AuthorizationException $error) {
            // The provider may have created a session while authority changed. Retain its binding, suppress it, close outside locks.
            DB::transaction(function()use($intent,$session){
                Event::whereKey($intent->event_id)->lockForUpdate()->firstOrFail();$i=PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
                if($i->status!=='paid'){
                    RecordWriteRejected::unless(!$i->stripe_checkout_session_id||$i->stripe_checkout_session_id===$session['id'],409,'La sesión requiere conciliación.');
                    $i->update(['stripe_checkout_session_id'=>$session['id'],'status'=>'superseded','collection_slot'=>null,'last_error'=>'authority_changed_during_dispatch']);
                }
            },3);
            try{$gateway->expireCheckout($session['id']);}catch(\Throwable){}throw $error;
        }
    }
    public static function verify(QuickStripeCheckoutRequest $r):array
    {
        try{
            $p=$r->envelope;$keys=['schema_version','request_id','booking_id','agreement_sha256','grant_id','lease_id','lease_sha256','terms','capabilities','created_at','provider_key','parameters'];
            $a=QuickPaymentAttempt::findOrFail($r->id);$b=\App\Models\QuickPaymentBooking::findOrFail($a->quick_payment_booking_id);
            QuickPaymentProtocol::verify($b,Event::findOrFail($b->event_id),false,true);
            $source=\App\Models\QuickStripeLeaseEvent::where('quick_stripe_lease_id',$b->quick_stripe_lease_id)->where('payload_sha256',$p['lease_sha256']??'')->firstOrFail();
            $t=QuickStripeTerms::restore($p['terms'])->snapshot();$methods=$p['capabilities']['methods'];
            $valid=count($p)===count($keys)&&array_diff($keys,array_keys($p))===[]&&$p['schema_version']===2&&$p['request_id']===$r->id&&$p['booking_id']===$b->id
                &&$p['agreement_sha256']===$b->agreement_sha256&&$p['grant_id']===$b->portal_grant_id&&$a->portal_grant_id===$b->portal_grant_id&&$p['lease_id']===$b->quick_stripe_lease_id
                &&$r->quick_stripe_lease_id===$p['lease_id']&&$r->event_id===$b->event_id&&$r->customer_id===$b->customer_id&&$r->financial_revision_id===$b->financial_revision_id
                &&$r->envelope_sha256===$a->request_sha256&&$r->envelope_sha256===MutationKey::hash($p)&&$source->payload_sha256===MutationKey::hash($source->payload)
                &&$source->payload['state']==='active'&&$source->payload['terms']['total_cents']===$t['total_cents']&&$source->payload['terms']['created_at']===$t['created_at']
                &&$p['created_at']===$r->created_at->timestamp&&$p['provider_key']==='waoo-quick-test:'.$r->id&&is_array($methods)&&$methods!==[]&&array_is_list($methods)&&array_diff($methods,['card','klarna'])===[]
                &&preg_match('/\Aacct_[A-Za-z0-9]+\z/',$p['capabilities']['account'])===1;
            $date=CarbonImmutable::parse($source->payload['window']['party_start'])->setTimezone('America/Chicago')->format('Y-m-d');
            $origin=parse_url($p['parameters']['success_url']);$base=($origin['scheme']??'').'://'.($origin['host']??'').(isset($origin['port'])?':'.$origin['port']:'');
            $valid=$valid&&$p['parameters']===QuickStripeCheckoutRequests::parameters($r->id,$b->event_id,$t,$date,$base,$methods,QuickStripeTerms::restore($t)->checkoutExpiresAt(CarbonImmutable::createFromTimestampUTC($p['created_at'])));
            $audit=DB::table('audit_entries')->where('action','quick_payment.request_prepared')->where('subject_id',$r->id)->where('subject_type',$r->getTable())->sole();
            $valid=$valid&&json_decode($audit->after,true)===['event_id'=>$b->event_id,'sha256'=>$r->envelope_sha256];
            RecordWriteRejected::unless($valid,409,'La evidencia del intento de pago requiere revisión.');return $p;
        }catch(\Illuminate\Database\QueryException $e){throw $e;}catch(RecordWriteRejected $e){throw $e;}catch(\Throwable){throw new RecordWriteRejected(409,'La evidencia del intento de pago requiere revisión.');}
    }
    public static function session(array $s,QuickStripeCheckoutRequest $r):void
    {
        $p=self::verify($r);$t=$p['terms'];
        RecordWriteRejected::unless(($s['livemode']??null)===false&&($s['_verified_account']??null)===$p['capabilities']['account']
            &&($s['client_reference_id']??null)===$r->id&&is_array($s['metadata']??null)&&MutationKey::hash($s['metadata'])===MutationKey::hash($p['parameters']['metadata'])&&($s['currency']??null)==='usd'
            &&is_array($s['payment_method_types']??null)&&array_diff($s['payment_method_types'],$p['capabilities']['methods'])===[]&&$s['payment_method_types']!==[]&&($s['amount_total']??null)===$t['payable_cents']&&is_string($s['id']??null)&&preg_match('/\Acs_test_[A-Za-z0-9_]+\z/',$s['id']),409,'Stripe no confirmó el acuerdo de pago.');
    }
}
