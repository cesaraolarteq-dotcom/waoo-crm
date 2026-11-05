<?php
namespace App\Services\Availability;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event,ImportedProjectLink,User};
use App\Services\Records\RecordActor;
use App\Services\Scheduling\DailyCapacity;
use App\Services\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Owner-only review. Similar times suggest candidates, never establish identity. No raw UID or feed URL leaves the service. */
final class ReviewCalendarIdentity
{
    public function read(User $actor,int $eventId): array
    {
        RecordActor::authorize($actor);
        RecordWriteRejected::unless(DB::transactionLevel()===0,409,'Revisa el calendario fuera de la transacción.');
        $e=Event::findOrFail($eventId);
        RecordWriteRejected::unless(ImportedProjectLink::where('event_id',$eventId)->exists(),422,'Selecciona una fiesta importada de HoneyBook.');
        $from=CarbonImmutable::instance($e->starts_at);$to=CarbonImmutable::instance($e->ends_at);
        $scope=HoneyBookCalendarFeed::scope();
        $entries=$scope===null?null:app(HoneyBookCalendarFeed::class)->between($from->subDay(),$to->addDay(),true);
        $links=DB::table('calendar_identity_links')->where('scope_sha256',$scope)->get();$options=[];$conflicts=0;
        foreach($entries??[] as $entry){
            if(!$entry[0]->equalTo($from)||!$entry[1]->equalTo($to)||empty($entry[2]['uid_sha256']))continue;
            $uid=$entry[2]['uid_sha256'];$bound=$links->firstWhere('uid_sha256',$uid);
            if($bound&&(int)$bound->event_id!==$eventId){$conflicts++;continue;}
            $options[]=['uid_sha256'=>$uid,'summary'=>$entry[2]['summary']??'','identity_hint'=>substr($uid,0,8)];
        }
        $binding=$links->firstWhere('event_id',$eventId);
        return ['event_id'=>$e->id,'expected_event_version'=>$e->version,'scope_sha256'=>$scope,'state'=>$entries===null?'unknown':'ready',
            'options'=>$options,'conflicts'=>$conflicts,'binding_id'=>$binding?->id,
            'binding_current'=>$binding&&$binding->starts_at===$from->utc()->format('Y-m-d H:i:s')&&$binding->ends_at===$to->utc()->format('Y-m-d H:i:s')];
    }

    public function remove(User $actor,int $eventId,int $expectedVersion,int $bindingId,string $reason,?string $expectedScope=null): void
    {
        RecordActor::authorize($actor);
        \App\Services\Records\RecordInput::validate(['reason'=>$reason],['reason'=>'required|string|min:10|max:1000']);
        $scope=HoneyBookCalendarFeed::scope();
        RecordWriteRejected::unless($scope!==null && ($expectedScope===null || $scope===$expectedScope),409,'Cambió el calendario. Vuelve a revisar la identidad.');
        DB::transaction(function()use($actor,$eventId,$expectedVersion,$bindingId,$reason,$scope){
            DailyCapacity::lock();User::whereKey($actor->id)->lockForUpdate()->firstOrFail();RecordActor::authorize($actor);
            $e=Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            RecordWriteRejected::unless($e->version===$expectedVersion && HoneyBookCalendarFeed::scope()===$scope,409,'La fiesta o el calendario cambió. Actualiza antes de revisar.');
            $link=DB::table('calendar_identity_links')->where('event_id',$eventId)->where('scope_sha256',$scope)->lockForUpdate()->first();
            if(!$link)return;
            RecordWriteRejected::unless((int)$link->id===$bindingId,409,'La asociación cambió. Vuelve a revisarla.');
            DB::table('calendar_identity_links')->where('id',$link->id)->delete();
            Audit::recordFor($actor,'calendar.identity_removed',$e, ['binding_id'=>$link->id,'uid_sha256'=>$link->uid_sha256],['reason'=>$reason]);
        },3);
    }
}
