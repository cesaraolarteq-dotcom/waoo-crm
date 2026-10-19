<?php
namespace App\Services\Mail;
use App\Models\{Event,EventMessagePolicy,OutboundMessage,OutboundMessageState,User};
use App\Services\Quotes\QuoteProtocol;
final class ReadOutboundMail
{
    public function forEvent(User $actor,int $eventId,array $filters=[]):array
    {
        MailGuard::actor($actor);Event::findOrFail($eventId);$p=EventMessagePolicy::where('event_id',$eventId)->first();
        return [...$this->page(OutboundMessage::where('event_id',$eventId),$filters),'policy'=>['version'=>$p?->version??0,'paused'=>$p?->paused??false]];
    }
    public function warnings(User $actor,array $filters=[]):array
    {
        MailGuard::actor($actor);
        $d=QuoteProtocol::input($filters,['page'=>'sometimes|integer|min:1','per_page'=>'sometimes|integer|min:1|max:50','status'=>'sometimes|in:bounced,complained,delivery_unknown,blocked,dispatching']);
        $statuses=isset($d['status'])?[$d['status']]:['bounced','complained','delivery_unknown','blocked','dispatching'];unset($d['status']);
        return $this->page(OutboundMessage::whereIn('id',OutboundMessageState::whereIn('status',$statuses)->select('outbound_message_id')),$d);

    }
    private function page($query,array $filters):array
    {
        $d=QuoteProtocol::input($filters,['page'=>'sometimes|integer|min:1','per_page'=>'sometimes|integer|min:1|max:50']);$size=(int)($d['per_page']??25);$total=(clone $query)->count();$last=max(1,(int)ceil($total/$size));$page=min($last,(int)($d['page']??1));
        $data=[];foreach($query->orderByDesc('id')->offset(($page-1)*$size)->limit($size)->get() as $m){$state=OutboundMessageState::where('outbound_message_id',$m->id)->firstOrFail();
            $data[]=[...$m->only(['id','event_id','lead_id','source_occurrence_id','template_key','template_version','locale','channel','environment','due_at']),...$state->only(['status','version','attempt_count','error_code','sent_at','updated_at'])];}
        return ['data'=>$data,'page'=>$page,'per_page'=>$size,'total'=>$total,'last_page'=>$last];
    }
    public function settings(User $actor):array
    {
        \App\Services\Portal\PortalContext::requireTransport(request());\App\Services\Records\RecordActor::authorize($actor);$templates=[];
        foreach(ApprovedMail::KEYS as $key)foreach(['es','en'] as $locale){try{$d=ApprovedMail::definition($key,$locale);$templates[]=['key'=>$key,'locale'=>$locale,'version'=>$d['version'],'approved'=>true];}catch(\Illuminate\Validation\ValidationException){$templates[]=['key'=>$key,'locale'=>$locale,'version'=>null,'approved'=>false];}}
        return ['environment'=>'sandbox','enabled'=>app()->environment(['local','testing','staging'])&&(bool)config('waoo.sandbox_mail'),'transport_configured'=>app(MailTransport::class)->configured(),'allowlist'=>MailGuard::allowlist(),'templates'=>$templates,'activation_required'=>true];
    }
}
