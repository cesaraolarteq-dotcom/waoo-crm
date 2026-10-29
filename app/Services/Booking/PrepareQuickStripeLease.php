<?php
namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Character, Event, FinancialRevision, QuickStripeLease, Resource, ResourceReservation, User};
use App\Services\{CostumeOutfits, MatchEventResources};
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\DB;

final class PrepareQuickStripeLease
{
    public function execute(User $actor,int $eventId,array $input,string $key):array
    {
        $actor=QuickStripeLeases::actor($actor);
        $data=QuickStripeLeases::input($input,['expected_event_version'=>'required|integer|min:1','financial_revision_id'=>'required|integer|min:1',
            'deposit_percent'=>'sometimes|required|integer|in:50,100']);$data['deposit_percent']??=50;
        $scope="owner:{$actor->id}:event:{$eventId}:quick-stripe-prepare";$hash=MutationKey::hash($data);
        return DB::transaction(function()use($actor,$eventId,$data,$scope,$hash,$key){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();QuickStripeLeases::actor($actor);
            $event=Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            $lease=QuickStripeLease::where('event_id',$eventId)->lockForUpdate()->first();
            if($receipt=MutationKey::find($scope,$key,$hash,true)){
                RecordWriteRejected::unless($lease&&$lease->id===(int)$receipt->result_id,409,'La solicitud no corresponde a este apartado.');
                return QuickStripeLeases::view($lease,$event);
            }
            RecordWriteRejected::unless(!$lease,409,'Esta fiesta ya tuvo un apartado. No se renueva con otra solicitud.');
            QuickStripeLeases::unpaid($event);
            RecordWriteRejected::unless($event->version===$data['expected_event_version']&&$event->starts_at->isFuture()
                &&!ResourceReservation::where('event_id',$eventId)->exists(),409,'La fiesta cambió, ya empezó o tiene recursos comprometidos.');
            $revision=FinancialRevision::whereKey($data['financial_revision_id'])->where('event_id',$eventId)->firstOrFail();
            RecordWriteRejected::unless($revision->event_version===$event->version&&$revision->currency==='USD'
                &&(int)FinancialRevision::where('event_id',$eventId)->max('revision')===$revision->revision,409,'Revisa la versión financiera vigente.');
            $compiled=QuickCostumeHolds::slots($event);$slots=$compiled['slots'];
            RecordWriteRejected::unless(!$compiled['missing']&&$event->services()->exists(),422,'Completa los personajes y requisitos antes de ofrecer un apartado sin pago.');
            \App\Services\Availability\AdmitEventCapacity::assertFits($event); // day locks before character/resource locks
            $characters=Character::whereIn('id',collect($slots)->pluck('character_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $units=Resource::where('kind','costume')->where('status','active')->whereIn('character_id',$characters->keys())->orderBy('id')->limit(501)->lockForUpdate()->get();
            RecordWriteRejected::unless($units->count()<=500,409,'El inventario requiere revisión por volumen.');
            $available=$units->filter(fn($unit)=>QuickCostumeHolds::available($event,$unit))->values();
            $assignment=app(MatchEventResources::class)->execute($slots,$available->all(),fn($slot,$unit)=>$characters->get($unit->character_id)?->active&&CostumeOutfits::fits($slot,$unit,$characters,[]));
            try{$terms=QuickStripeTerms::freeze($revision->total_cents,\Carbon\CarbonImmutable::now(),$data['deposit_percent'])->snapshot();}
            catch(\InvalidArgumentException){throw new RecordWriteRejected(422,'Este importe requiere revisión antes de preparar un cobro de Stripe.');}
            $lease=QuickStripeLease::create(['event_id'=>$eventId,'customer_id'=>$event->customer_id,'financial_revision_id'=>$revision->id,'version'=>0,'state'=>'active',
                'expires_at'=>\Carbon\CarbonImmutable::createFromTimestampUTC($terms['expires_at']),'payload'=>[],'payload_sha256'=>str_repeat('0',64)]);
            $rows=[];
            foreach($assignment as $resourceIndex=>$slotIndex){
                $unit=$available[$resourceIndex];$slot=$slots[$slotIndex];
                $reservation=ResourceReservation::create(['event_id'=>$eventId,'resource_id'=>$unit->id,'starts_at'=>$event->starts_at,'ends_at'=>$event->ends_at]);
                $rows[]=['resource_id'=>$unit->id,'resource_version'=>$unit->version,'character_id'=>$unit->character_id,'character_version'=>$characters[$unit->character_id]->version,
                    'character_name'=>$characters[$unit->character_id]->name,'outfit'=>$unit->outfit??($slot['outfit']??null),'reservation_id'=>$reservation->id];
            }
            $issues=($event->setup_minutes===null||$event->breakdown_minutes===null)?['timing_unknown']:[];
            QuickStripeLeases::append($actor,$lease,['schema_version'=>1,'lease_id'=>$lease->id,'event_id'=>$eventId,'customer_id'=>$event->customer_id,
                'financial_revision_id'=>$revision->id,'event_version'=>$event->version,'financial_sha256'=>QuickStripeLeases::financialHash($revision),'event_sha256'=>QuickStripeLeases::eventHash($event),
                'recipe_sha256'=>MutationKey::hash(QuickCostumeHolds::recipe($event)),'window'=>QuickCostumeHolds::window($event),'terms'=>$terms,'state'=>'active','rows'=>$rows,'issues'=>$issues,'reason'=>null]);
            MutationKey::store($scope,$key,$hash,'quick_stripe_lease',$lease->id);
            return QuickStripeLeases::view($lease,$event);
        },3);
    }
}
