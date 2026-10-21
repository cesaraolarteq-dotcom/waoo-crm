<?php
namespace App\Services\Booking;
use App\Models\{ClientDetailsSubmission,Event,EventMessagePolicy,LedgerEntry,OutboundMessage,OutboundMessageState,PortalGrant,QuickPaymentBooking,QuickPaymentNotification};
use App\Services\Finance\{FinanceSummary,MutationKey,Sandbox};
use App\Services\Mail\{ApprovedMail,MailGuard,MailTransport,OutboundEnvelope};
use App\Services\Portal\PortalTokenVault;
use App\Support\ApprovedClientTexts;
use Illuminate\Support\Facades\DB;
final class DispatchQuickPaymentNotifications
{
    public function execute(int $limit=100):void
    {
        QuickPaymentProtocol::gate(true);
        // Explicitly separate storing automatic notices from enabling outbound transport.
        if(!config('waoo.sandbox_quick_payment_notifications')||!config('waoo.sandbox_mail'))return;
        $transport=app(MailTransport::class);if(!$transport->configured())return;
        foreach(QuickPaymentNotification::where('status','pending')->where('due_at','<=',now())->orderBy('id')->limit($limit)->pluck('id') as $id){
            $mail=DB::transaction(function()use($id){
                $stub=QuickPaymentNotification::findOrFail($id);$stubB=QuickPaymentBooking::findOrFail($stub->quick_payment_booking_id);
                $g=PortalGrant::whereKey($stubB->portal_grant_id)->lockForUpdate()->firstOrFail();$e=Event::whereKey($stubB->event_id)->lockForUpdate()->firstOrFail();
                $b=QuickPaymentProtocol::booking($e->id);$n=QuickPaymentNotification::whereKey($id)->lockForUpdate()->firstOrFail();if($n->status!=='pending')return null;
                if($e->responsible_system!=='crm'||$g->revoked_at||!$g->expires_at->isFuture()||EventMessagePolicy::where('event_id',$e->id)->value('paused'))return null;
                if($n->payload_sha256!==MutationKey::hash($n->payload)||$n->payload['agreement_sha256']!==$b->agreement_sha256){$n->update(['status'=>'blocked','error_code'=>'source_integrity_failed']);return null;}
                QuickPaymentProtocol::verify($b,$e);
                if($n->kind==='reminder_24h'&&($b->state!=='awaiting_payment'||!$b->expires_at->isFuture()||QuickPaymentProtocol::pendingNotice($b))){$n->update(['status'=>'skipped','error_code'=>'no_longer_due']);return null;}
                $payload=$n->payload;$recipient=$payload['recipient']??null;$mail=$payload['mail']??null;
                if(!is_string($recipient)||!is_array($mail))return null;
                $suppressed=OutboundMessageState::whereIn('outbound_message_id',OutboundMessage::where('recipient_sha256',hash('sha256',$recipient))->select('id'))->whereIn('status',['bounced','complained'])->exists();
                if(!in_array($recipient,MailGuard::allowlist(),true)||$suppressed)return null;
                $locale=$payload['locale'];$approved=$n->kind==='payment_received'?MutationKey::hash(ApprovedMail::definition('payment_received',$locale)):MutationKey::hash(config('client-texts.texts.stripe_reminder_24h'));
                if(!hash_equals($approved,$mail['template_sha256'])){$n->update(['status'=>'blocked','error_code'=>'template_changed']);return null;}
                $n->update(['status'=>'dispatching','error_code'=>null]);
                return new OutboundEnvelope($n->id,$recipient,$mail['subject'],$mail['html'],$mail['text']);
            },3);
            if(!$mail)continue;
            try{$receipt=$transport->send($mail);if(!$receipt->messageId)throw new \RuntimeException();$status='sent';$error=null;}
            catch(\Throwable){$status='delivery_unknown';$error='transport_outcome_unknown';}
            QuickPaymentNotification::whereKey($id)->where('status','dispatching')->update(['status'=>$status,'error_code'=>$error]);
        }
    }
}
