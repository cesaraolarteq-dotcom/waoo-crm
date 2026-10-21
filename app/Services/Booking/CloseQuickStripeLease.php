<?php
namespace App\Services\Booking;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, QuickStripeLease, ResourceReservation, User};
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\DB;
/** Internal shared transition; public owner adapters validate the two distinct intent shapes. */
final class CloseQuickStripeLease
{
    public function execute(User $actor,int $eventId,array $data,string $key,bool $expiry):array
    {
        $actor=QuickStripeLeases::actor($actor);$action=$expiry?'expire':'release';$scope="owner:{$actor->id}:event:{$eventId}:quick-stripe-{$action}";$hash=MutationKey::hash($data);
        return DB::transaction(function()use($actor,$eventId,$data,$key,$expiry,$scope,$hash){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();QuickStripeLeases::actor($actor);
            $event=Event::whereKey($eventId)->lockForUpdate()->firstOrFail();$lease=QuickStripeLease::where('event_id',$eventId)->lockForUpdate()->firstOrFail();
            if($saved=MutationKey::find($scope,$key,$hash,true)){
                RecordWriteRejected::unless((int)$saved->result_id===$lease->id,409,'La solicitud pertenece a otro apartado.');return QuickStripeLeases::view($lease,$event);
            }
            RecordWriteRejected::unless($lease->state==='active'&&$lease->version===$data['expected_lease_version']
                &&($expiry||$event->version===$data['expected_event_version']),409,'El apartado cambió. Revisa la versión mostrada.');
            $p=QuickStripeLeases::verify($lease,$event,false);
            QuickStripeLeases::lockInventory($p);
            $p=QuickStripeLeases::verify($lease,$event);
            RecordWriteRejected::unless(!$expiry||now()->timestamp >= $p['terms']['expires_at'],409,'El plazo original de 48 horas todavía no vence.');
            ResourceReservation::whereIn('id',array_column($p['rows'],'reservation_id'))->where('event_id',$eventId)->delete();
            QuickStripeLeases::append($actor,$lease,[...$p,'state'=>$expiry?'expired':'cancelled','rows'=>[],'reason'=>$expiry?'original_48h_deadline':$data['reason']]);
            MutationKey::store($scope,$key,$hash,'quick_stripe_lease',$lease->id);
            return QuickStripeLeases::view($lease,$event);
        },3);
    }
}
