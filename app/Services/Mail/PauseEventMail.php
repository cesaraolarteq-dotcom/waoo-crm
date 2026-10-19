<?php
namespace App\Services\Mail;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event,EventMessagePolicy,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Quotes\QuoteProtocol;
final class PauseEventMail
{
    public function execute(User $actor,int $eventId,array $input,string $key):array
    {
        $actor=MailGuard::actor($actor);$d=QuoteProtocol::input($input,['expected_version'=>'required|integer|min:0','paused'=>'required|boolean']);$d=['expected_version'=>(int)$d['expected_version'],'paused'=>(bool)$d['paused']];$hash=MutationKey::hash($d);$scope="owner:{$actor->id}:event:{$eventId}:mail:policy";
        return MailGuard::transaction(function()use($actor,$eventId,$d,$hash,$scope,$key){
            Event::whereKey($eventId)->lockForUpdate()->firstOrFail();MailGuard::actor($actor);
            if($r=MutationKey::find($scope,$key,$hash))return ['id'=>(int)$r->result_id,'version'=>$d['expected_version']+1,'paused'=>$d['paused'],'replayed'=>true];
            $p=EventMessagePolicy::where('event_id',$eventId)->lockForUpdate()->first()??new EventMessagePolicy(['event_id'=>$eventId,'version'=>0]);
            RecordWriteRejected::unless($p->version===$d['expected_version'],409,'mail_policy_changed');$p->fill(['paused'=>$d['paused'],'version'=>$p->version+1,'updated_by'=>$actor->id])->save();
            Audit::recordFor($actor,'mail.policy_updated',$p,[],['version'=>$p->version,'paused'=>$p->paused]);MutationKey::store($scope,$key,$hash,'event_message_policy',$p->id);return ['id'=>$p->id,'version'=>$p->version,'paused'=>$p->paused,'replayed'=>false];
        });
    }
}
