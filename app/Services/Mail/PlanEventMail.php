<?php
namespace App\Services\Mail;
use App\Exceptions\RecordWriteRejected;
use App\Models\{OutboundMessage,OutboundMessageState,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Quotes\QuoteProtocol;
final class PlanEventMail
{
    public function execute(User $actor,int $eventId,array $input,string $key):array
    {
        $actor=MailGuard::actor($actor);$d=QuoteProtocol::input($input,['expected_event_version'=>'required|integer|min:1','expected_customer_version'=>'required|integer|min:1','portal_grant_id'=>'required|integer|min:1',
            'templates'=>'sometimes|array|min:1|max:3','templates.*'=>'required|distinct|in:balance_reminder,week_before,thank_you_review']);
        foreach(['expected_event_version','expected_customer_version','portal_grant_id'] as $f)$d[$f]=(int)$d[$f];$d['templates']??=EventMailSource::REMINDERS;sort($d['templates']);$hash=MutationKey::hash($d);$scope="owner:{$actor->id}:event:{$eventId}:mail:plan";
        return MailGuard::transaction(function()use($actor,$eventId,$d,$hash,$scope,$key){
            // Grant before event, shared with revoke and existing portal operations. Replay before dynamic source checks.
            \App\Models\PortalGrant::whereKey($d['portal_grant_id'])->lockForUpdate()->firstOrFail();$event=\App\Models\Event::whereKey($eventId)->lockForUpdate()->firstOrFail();MailGuard::actor($actor);
            \App\Services\Responsibility\RequireCrmResponsibility::check($event);
            if($r=MutationKey::find($scope,$key,$hash))return ['message_ids'=>($r->result_id===''?[]:array_map('intval',explode(',',$r->result_id))),'replayed'=>true];
            $source=EventMailSource::lock($eventId,$d['portal_grant_id']);[$e,$c,$f,$g,$net]=$source;
            RecordWriteRejected::unless($e->version===$d['expected_event_version']&&$c->version===$d['expected_customer_version'],409,'mail_source_changed');$ids=[];$skipped=[];
            foreach($d['templates'] as $template){
                if($template==='balance_reminder'&&$net>=$f->total_cents){$skipped[]=['template'=>$template,'reason'=>'no_balance'];continue;}
                $due=EventMailSource::due($template,$e,$f);
                if(!$due->isFuture()){$skipped[]=['template'=>$template,'reason'=>'past_due'];continue;}
                RecordWriteRejected::unless($g->expires_at->greaterThan($due),409,'mail_link_expires_before_reminder');
                $rendered=EventMailSource::compose($template,$source);$intent=MutationKey::hash(['event'=>$e->id,'template'=>$template,'source'=>$rendered['source_sha256'],'due'=>$due->toIso8601String()]);
                if($existing=OutboundMessage::where('intent_sha256',$intent)->lockForUpdate()->first()){$ids[]=$existing->id;continue;}
                $m=OutboundMessage::create(['event_id'=>$e->id,'customer_id'=>$c->id,'financial_revision_id'=>$f->id,'portal_grant_id'=>$g->id,'created_by'=>$actor->id,
                    'event_version'=>$e->version,'customer_version'=>$c->version,'template_key'=>$template,'template_version'=>$rendered['template_version'],'locale'=>$c->locale,'channel'=>'email','environment'=>'sandbox',
                    'template_sha256'=>$rendered['template_sha256'],'recipient_sha256'=>hash('sha256',$rendered['recipient']),'intent_sha256'=>$intent,'source_sha256'=>$rendered['source_sha256'],'due_at'=>$due,'payload'=>$rendered]);
                OutboundMessageState::create(['outbound_message_id'=>$m->id,'status'=>'scheduled']);MailGuard::event($m->id,'scheduled:'.$m->id,'scheduled');
                Audit::recordFor($actor,'mail.planned',$m,[],['event_id'=>$e->id,'template_key'=>$template]);$ids[]=$m->id;
            }
            // Empty plan also has a replay receipt, represented by the stable empty string.
            MutationKey::store($scope,$key,$hash,'outbound_messages',implode(',',$ids));return ['message_ids'=>$ids,'skipped'=>$skipped,'replayed'=>false];
        });
    }
}
