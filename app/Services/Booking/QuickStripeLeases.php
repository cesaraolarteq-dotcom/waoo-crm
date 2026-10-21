<?php
namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Character, Event, FinancialRevision, LedgerEntry, PaymentIntent, QuickStripeLease, QuickStripeLeaseEvent, Resource, ResourceReservation, User};
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Portal\PortalContext;
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Unpaid stock commitment only. It never creates a receipt, portal grant, provider intent or staff assignment. */
final class QuickStripeLeases
{
    public static function actor(User $actor):User
    {
        Sandbox::require();Sandbox::require('sandbox_documents');Sandbox::require('sandbox_quick_stripe');
        PortalContext::requireTransport(request());return RecordActor::authorize($actor);
    }
    public static function input(array $input,array $rules):array
    {
        foreach(array_diff(array_keys($input),array_keys($rules)) as $key)throw ValidationException::withMessages([$key=>'Este campo no se acepta.']);
        $data=RecordInput::validate($input,$rules);
        foreach(['expected_event_version','expected_lease_version','financial_revision_id','deposit_percent'] as $key)if(isset($data[$key]))$data[$key]=(int)$data[$key];
        return $data;
    }
    public static function assertNoActive(int $eventId):void
    {
        // Do not trust a mutable terminal label when its historical envelope is corrupt.
        $lease=QuickStripeLease::where('event_id',$eventId)->lockForUpdate()->first();
        if(!$lease)return;
        RecordWriteRejected::unless(in_array($lease->state,['cancelled','expired','promoted'],true),409,
            'Esta fiesta tiene un apartado pendiente de Stripe. Libéralo y revisa su acuerdo antes de cambiarlo, cobrar o confirmar por otro camino.');
        self::verify($lease,Event::findOrFail($eventId),false);
    }

    public static function financialHash(FinancialRevision $revision):string
    {
        return MutationKey::hash(['schema_version'=>1,'revision'=>$revision->only(['id','event_id','revision','event_version','currency','lines_snapshot',
            'subtotal_cents','travel_fee_cents','discount_cents','discounts_snapshot','tax_cents','customer_fee_cents','total_cents','deposit_target_cents','balance_due_at','quote_revision_id','created_by'])]);
    }
    public static function eventHash(Event $event):string
    {
        return MutationKey::hash(['schema_version'=>1,'event'=>$event->only(['id','customer_id','version','status','responsible_system','title','event_type',
            'starts_at','ends_at','timezone','address','postal_code','setup_minutes','breakdown_minutes','travel_fee_cents'])]);
    }
    public static function unpaid(Event $event):void
    {
        \App\Services\Responsibility\RequireCrmResponsibility::check($event);
        \App\Services\Imports\ImportedAgreement::blockCrmLifecycle($event->id);
        $booking=QuickPaymentProtocol::booking($event->id);
        if($booking) QuickPaymentProtocol::verify($booking,$event);
        $intents=PaymentIntent::where('event_id',$event->id);
        if($booking) $intents->whereNotIn('id',\App\Models\QuickPaymentAttempt::where('quick_payment_booking_id',$booking->id)->select('payment_intent_id'));
        $contracts=\App\Models\Contract::where('event_id',$event->id);
        if($booking) $contracts->where(function($q)use($booking){$q->whereNull('commercial_snapshot->quick_payment_source')->orWhere('commercial_snapshot->quick_payment_source->agreement_sha256','!=',$booking->agreement_sha256);});
        $grants=\App\Models\PortalGrant::where('event_id',$event->id);
        if($booking) $grants->where('id','!=',$booking->portal_grant_id);
        RecordWriteRejected::unless($event->status==='draft' && !LedgerEntry::where('event_id',$event->id)->exists()
            && !$intents->exists() && !$contracts->exists()
            && !\App\Models\QuickBookingRecord::where('event_id',$event->id)->exists() && !\App\Models\Quote::where('event_id',$event->id)->exists() && !$grants->exists(),
            409,'El acuerdo ya tiene pagos, cobros, contrato o cotización. Revisa la fiesta antes de preparar un apartado sin pago.');
    }
    public static function verify(QuickStripeLease $lease,Event $event,bool $inventory=true,bool $recordedPayment=false):array
    {
        try {
            $p=$lease->payload;$history=QuickStripeLeaseEvent::where('quick_stripe_lease_id',$lease->id)->where('version',$lease->version)->sharedLock()->first();
            $keys=['schema_version','lease_id','event_id','customer_id','financial_revision_id','event_version','financial_sha256','event_sha256','recipe_sha256',
                'window','terms','version','state','rows','issues','actor_id','reason','previous_sha256'];
            RecordWriteRejected::unless(is_array($p)&&count($p)===count($keys)&&array_diff($keys,array_keys($p))===[]&&$p['schema_version']===1
                &&$p['lease_id']===$lease->id&&$p['event_id']===$event->id&&$lease->event_id===$event->id
                &&$p['customer_id']===$lease->customer_id&&$p['financial_revision_id']===$lease->financial_revision_id
                &&$p['state']===$lease->state&&in_array($lease->state,['active','cancelled','expired','promoted'],true)
                &&$p['version']===$lease->version&&hash_equals($lease->payload_sha256,MutationKey::hash($p))
                &&$history&&$history->payload===$p&&$history->payload_sha256===$lease->payload_sha256,409,'La evidencia del apartado requiere revisión.');
            RecordWriteRejected::unless(is_array($p['rows'])&&array_is_list($p['rows'])&&count($p['rows'])<=100,409,'La lista de unidades requiere revisión.');
            foreach($p['rows'] as $row){
                $fields=['resource_id','resource_version','character_id','character_version','character_name','outfit','reservation_id'];
                RecordWriteRejected::unless(is_array($row)&&count($row)===count($fields)&&array_diff($fields,array_keys($row))===[],409,'La unidad requiere revisión.');
                foreach(['resource_id','resource_version','character_id','character_version','reservation_id'] as $field)RecordWriteRejected::unless(is_int($row[$field])&&$row[$field]>0,409,'La unidad requiere revisión.');
                RecordWriteRejected::unless(is_string($row['character_name'])&&($row['outfit']===null||is_string($row['outfit'])),409,'El vestuario requiere revisión.');
            }
            $terms=QuickStripeTerms::restore($p['terms'])->snapshot();
            RecordWriteRejected::unless($lease->expires_at->timestamp===$terms['expires_at'],409,'El vencimiento del apartado requiere revisión.');
            $previous=$lease->version===1?null:QuickStripeLeaseEvent::where('quick_stripe_lease_id',$lease->id)->where('version',$lease->version-1)->sharedLock()->first();
            RecordWriteRejected::unless($lease->version===1?$p['previous_sha256']===null:($previous&&$previous->payload_sha256===$p['previous_sha256']),409,'El historial del apartado requiere revisión.');
            $revision=FinancialRevision::whereKey($lease->financial_revision_id)->firstOrFail();
            RecordWriteRejected::unless($revision->event_id===$event->id&&self::financialHash($revision)===$p['financial_sha256']
                &&$revision->total_cents===$terms['total_cents'],409,'El acuerdo financiero del apartado requiere revisión.');
            if($lease->state==='active'){
                if(!$recordedPayment) self::unpaid($event);
                else {
                    $booking=QuickPaymentProtocol::booking($event->id);
                    RecordWriteRejected::unless($booking && LedgerEntry::where('event_id',$event->id)->where('financial_revision_id',$booking->financial_revision_id)->where('kind','receipt')->whereIn('source_type',['owner_verified','verified_quick_stripe'])->exists(),409,'Falta el pago verificado de la reserva.');
                }
                RecordWriteRejected::unless($event->customer_id===$lease->customer_id&&$event->version===$p['event_version']
                    &&self::eventHash($event)===$p['event_sha256']&&QuickCostumeHolds::window($event)===$p['window']
                    &&MutationKey::hash(QuickCostumeHolds::recipe($event))===$p['recipe_sha256']
                    &&FinancialRevision::where('event_id',$event->id)->max('revision')===$revision->revision,409,'La fiesta o su receta cambió; revisa el apartado.');
                if($inventory){
                $seen=[];
                foreach($p['rows'] as $row){
                    RecordWriteRejected::unless(!in_array($row['resource_id'],$seen,true),409,'La unidad está repetida en la evidencia.');$seen[]=$row['resource_id'];
                    $reservation=ResourceReservation::whereKey($row['reservation_id'])->sharedLock()->first();
                    $unit=Resource::whereKey($row['resource_id'])->sharedLock()->first();$character=Character::whereKey($row['character_id'])->sharedLock()->first();
                    RecordWriteRejected::unless($reservation&&$unit&&$character&&$reservation->event_id===$event->id&&$reservation->resource_id===$unit->id
                        &&$reservation->starts_at->toIso8601String()===$p['window']['party_start']&&$reservation->ends_at->toIso8601String()===$p['window']['party_end']
                        &&$unit->kind==='costume'&&$unit->status==='active'&&$unit->version===$row['resource_version']&&$unit->character_id===$character->id
                        &&$character->active&&$character->version===$row['character_version']
                        &&($unit->outfit===null?$row['outfit']===null||in_array($row['outfit'],$character->outfits??[],true):$unit->outfit===$row['outfit']),
                        409,'El inventario apartado cambió; no se liberará otra unidad.');
                }
                RecordWriteRejected::unless(ResourceReservation::where('event_id',$event->id)->count()===count($p['rows']),409,'La fiesta tiene reservas ajenas a este apartado.');
                $slots=QuickCostumeHolds::slots($event)['slots'];
                RecordWriteRejected::unless(count($slots)===count($p['rows']),409,'El apartado no cubre la receta congelada.');
                $units=Resource::whereIn('id',array_column($p['rows'],'resource_id'))->orderBy('id')->get();
                $characters=Character::whereIn('id',array_column($p['rows'],'character_id'))->get()->keyBy('id');$rows=collect($p['rows'])->keyBy('resource_id');
                app(\App\Services\MatchEventResources::class)->execute($slots,$units->all(),fn($slot,$unit)=>\App\Services\CostumeOutfits::fits($slot,$unit,$characters,[])
                    &&(!isset($slot['outfit'])||$rows[$unit->id]['outfit']===$slot['outfit']));

                }
            }else RecordWriteRejected::unless($p['rows']===[],409,'Un apartado cerrado conserva unidades inesperadas.');
            if($lease->state==='promoted'){
                $b=QuickPaymentProtocol::booking($event->id);
                RecordWriteRejected::unless($b && $b->state==='paid' && $b->quick_booking_id!==null && $b->receipt_id!==null,409,'El pago del apartado requiere revisión.');
                QuickPaymentProtocol::verify($b,$event,false);QuickCostumeHolds::record($event);
            }
            return $p;
        }catch(RecordWriteRejected $e){throw $e;}
        catch(\Illuminate\Database\QueryException $e){throw $e;}
        catch(\Throwable){throw new RecordWriteRejected(409,'La evidencia del apartado requiere revisión.');}
    }
    public static function lockInventory(array $p):void
    {
        Character::whereIn('id',array_column($p['rows'],'character_id'))->orderBy('id')->lockForUpdate()->get();
        Resource::whereIn('id',array_column($p['rows'],'resource_id'))->orderBy('id')->lockForUpdate()->get();
    }
    public static function append(?User $actor,QuickStripeLease $lease,array $payload):void
    {
        $p=[...$payload,'version'=>$lease->version+1,'actor_id'=>$actor?->id,'previous_sha256'=>$lease->version===0?null:$lease->payload_sha256];
        $hash=MutationKey::hash($p);$lease->fill(['version'=>$p['version'],'state'=>$p['state'],'payload'=>$p,'payload_sha256'=>$hash])->save();
        QuickStripeLeaseEvent::create(['quick_stripe_lease_id'=>$lease->id,'version'=>$lease->version,'payload'=>$p,'payload_sha256'=>$hash]);
        $meta=['event_id'=>$lease->event_id,'version'=>$lease->version,'sha256'=>$hash];
        $actor ? Audit::recordFor($actor,'quick_stripe_lease.'.$p['state'],$lease,[],$meta) : Audit::recordSystem('quick_stripe_lease.'.$p['state'],$lease,[],$meta);
    }
    public static function view(QuickStripeLease $lease,Event $event):array
    {
        $p=self::verify($lease,$event,false);if($lease->state==='active')self::lockInventory($p);
        $p=self::verify($lease,$event);$terms=$p['terms'];unset($terms['schema_version'],$terms['total_cents']);
        return ['event_id'=>$event->id,'event_version'=>$event->version,'lease_id'=>$lease->id,'version'=>$lease->version,'state'=>$lease->state,
            'terms'=>$terms,'issues'=>$p['issues'],'rows'=>$p['rows'],'staff_confirmed'=>false];
    }
    public static function protectCharacter(Character $character,array $data):void
    {
        // Caller holds character first; use only immutable envelope reads here, not event/lease row locks in reverse order.
        $events=ResourceReservation::query()->join('resources','resources.id','=','resource_reservations.resource_id')->where('resources.character_id',$character->id)->pluck('event_id');
        foreach(QuickStripeLease::whereIn('event_id',$events)->get() as $lease){
            try{$p=$lease->payload;RecordWriteRejected::unless(hash_equals($lease->payload_sha256,MutationKey::hash($p))&&$p['state']===$lease->state&&in_array($lease->state,['active','cancelled','expired','promoted'],true),409,'El apartado requiere revisión.');}
            catch(\Throwable){throw new RecordWriteRejected(409,'El apartado requiere revisión.');}
            if($lease->state!=='active'){RecordWriteRejected::unless($p['rows']===[],409,'El apartado requiere revisión.');continue;}
            // Any catalog version change would invalidate a frozen pending agreement: release/review first.
            throw new RecordWriteRejected(409,'Este personaje tiene un apartado de Stripe pendiente. Libéralo y revisa su acuerdo antes de editar el catálogo.');
        }
    }
}
