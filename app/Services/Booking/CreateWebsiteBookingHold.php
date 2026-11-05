<?php
namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{JointCapacityHold,User};
use App\Services\Availability\PrepareInitialCapacity;
use App\Services\Finance\MutationKey;
use App\Services\Records\RecordActor;

/** Trusted server entry for a future website adapter. Public availability remains preview; no payment or notification here. */
final class CreateWebsiteBookingHold
{
    public function execute(User $actor,array $input,string $key): UnpaidQuickBooking
    {
        $actor=RecordActor::authorize($actor);
        RecordWriteRejected::unless(!isset($input['deposit']) && ($input['deposit_received']??false)===false,422,'Este flujo prepara el apartado; no registra dinero recibido.');
        $input['deposit_received']=false;
        $replay=\Illuminate\Support\Facades\DB::table('mutation_receipts')->where('scope',"owner:{$actor->id}:quick-book")->where('request_key',$key)->exists();
        $proof=$replay?null:app(PrepareInitialCapacity::class)->execute($actor,$input);
        if($proof!==null)RecordWriteRejected::unless($proof['state']==='available',409,'La fecha necesita revisión antes de ofrecer una reserva automática.');
        $result=app(CreateUnpaidQuickBooking::class)->execute($actor,$input,$key,$proof);
        RecordWriteRejected::unless(JointCapacityHold::where('event_id',$result->event->id)->exists(),409,'El apartado original requiere revisión del equipo completo.');
        return $result;
    }
}
