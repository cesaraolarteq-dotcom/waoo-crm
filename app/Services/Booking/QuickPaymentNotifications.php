<?php
namespace App\Services\Booking;
use App\Models\{QuickPaymentBooking,QuickPaymentNotification,LedgerEntry,Event,ClientDetailsSubmission,PortalGrant};
use App\Services\Finance\{MutationKey,FinanceSummary,Sandbox};
use App\Services\Mail\ApprovedMail;
use App\Support\ApprovedClientTexts;
use App\Services\Portal\PortalTokenVault;
final class QuickPaymentNotifications
{
    public function paid(QuickPaymentBooking $b,LedgerEntry $entry):void{$this->freeze($b,'payment_received',$entry);}
    public function reminder(QuickPaymentBooking $b):bool{return $this->freeze($b,'reminder_24h')->wasRecentlyCreated;}
    private function freeze(QuickPaymentBooking $b,string $kind,?LedgerEntry $entry=null):QuickPaymentNotification
    {
        $identity=hash('sha256','quick-payment:'.($entry?'receipt:'.$entry->id:'booking:'.$b->id).':'.$kind);
        if($n=QuickPaymentNotification::where('identity_sha256',$identity)->first())return $n;
        $payload=['schema_version'=>1,'booking_id'=>$b->id,'agreement_sha256'=>$b->agreement_sha256,'receipt_id'=>$entry?->id];$status='pending';$error=null;
        try{
            $e=Event::findOrFail($b->event_id);$s=ClientDetailsSubmission::where('event_id',$e->id)->where('portal_grant_id',$b->portal_grant_id)->orderByDesc('revision')->first();
            // A reminder may precede details; never guess a recipient or fabricate a name.
            if(!$s)throw new \LogicException('recipient_missing');
            $d=$s->data;$locale=$d['locale']??'es';$summary=app(FinanceSummary::class)->forEvent($e);$f=$summary['financial_revision'];$money=fn($c)=>'$'.number_format($c/100,2);
            if($entry){
                $due=$f->balance_due_at?->setTimezone('America/Chicago')->locale($locale)->translatedFormat('D j M, g:i a');
                $gross=\App\Models\QuickPaymentReceipt::where('ledger_entry_id',$entry->id)->value('gross_cents');
                if($gross===null)throw new \LogicException('verified_gross_missing');
                $mail=ApprovedMail::render('payment_received',$locale,['first_name'=>$d['first_name'],'amount'=>$money((int)$gross),
                    'paid'=>$money($summary['net_received_cents']),'balance'=>$money($summary['balance_cents']),
                    'balance_due'=>$summary['balance_cents']>0&&$due?(($locale==='es'?', vence el ':', due ').$due):''],[]);
            }else{
                $text=ApprovedClientTexts::text('stripe_reminder_24h',$locale);$g=PortalGrant::findOrFail($b->portal_grant_id);
                $text=strtr($text,['{first_name}'=>$d['first_name'],'{event_date}'=>$e->starts_at->setTimezone('America/Chicago')->locale($locale)->translatedFormat('D j M Y'),
                    '{hold_until}'=>$b->expires_at->setTimezone('America/Chicago')->locale($locale)->translatedFormat('D j M, g:i a'),'{link}'=>Sandbox::origin().'/portal#token='.PortalTokenVault::open($g)]);
                $mail=['subject'=>'Waoo Party','text'=>$text,'html'=>'<p>'.nl2br(e($text)).'</p>','template_version'=>config('client-texts.version'),'template_sha256'=>MutationKey::hash(config('client-texts.texts.stripe_reminder_24h'))];
            }
            $payload+=['recipient'=>mb_strtolower($d['email']),'locale'=>$locale,'submission_id'=>$s->id,'mail'=>$mail];
        }catch(\Illuminate\Database\QueryException $e){throw $e;}catch(\Throwable){$status='blocked';$error='recipient_or_approved_template_missing';}
        return QuickPaymentNotification::create(['identity_sha256'=>$identity,'quick_payment_booking_id'=>$b->id,'kind'=>$kind,'status'=>$status,
            'payload'=>$payload,'payload_sha256'=>MutationKey::hash($payload),'due_at'=>$kind==='reminder_24h'?$b->reminder_at:now(),'error_code'=>$error]);
    }
}
