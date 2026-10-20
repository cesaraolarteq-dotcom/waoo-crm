<?php
namespace App\Services\Mail;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer,Event,FinancialRevision,LedgerEntry,PortalGrant};
use App\Services\Finance\{MutationKey,Sandbox};
use App\Services\Imports\ImportedAgreement;
use App\Services\Portal\PortalTokenVault;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
final class EventMailSource
{
    public const REMINDERS=['balance_reminder','week_before','thank_you_review'];
    // Caller locks grant before event, then customer and policy/state. All mutable source reads are current.
    public static function lock(int $eventId,int $grantId):array
    {
        $grant=PortalGrant::whereKey($grantId)->lockForUpdate()->firstOrFail();$event=Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
        \App\Services\Responsibility\RequireCrmResponsibility::check($event);
        $customer=Customer::whereKey($event->customer_id)->lockForUpdate()->firstOrFail();ImportedAgreement::blockCrmLifecycle($eventId);
        $finance=FinancialRevision::where('event_id',$eventId)->orderByDesc('revision')->lockForUpdate()->firstOrFail();
        $binding=DB::table('capacity_payment_links')->where('event_id',$eventId)->lockForUpdate()->first();
        RecordWriteRejected::unless($event->status==='capacity_reserved'&&$event->version===$finance->event_version+1&&$binding&&(int)$binding->financial_revision_id===$finance->id,409,'mail_event_requires_current_confirmed_finance');
        RecordWriteRejected::unless($grant->event_id===$eventId&&$grant->customer_id===$customer->id&&$grant->quote_revision_id===null&&!$grant->revoked_at&&$grant->expires_at->isFuture()&&in_array('payments.read',$grant->scopes,true),409,'mail_link_inactive');
        $ledger=LedgerEntry::where('event_id',$eventId)->where('environment','test')->orderBy('id')->lockForUpdate()->get();
        $net=(int)$ledger->sum(fn($line)=>$line->kind==='receipt'?$line->amount_cents:-$line->amount_cents);
        return [$event,$customer,$finance,$grant,$net];
    }
    // Thank-you: owner 2026-10-18, next Houston day at 10:00 am.
    public static function due(string $key,Event $e,FinancialRevision $f):CarbonImmutable
    {
        if($key==='balance_reminder'&&$f->balance_due_at===null)ApprovedMail::fail('balance_due_date_required');
        return match($key){'balance_reminder'=>$f->balance_due_at->subHours(24),
            'week_before'=>$e->starts_at->setTimezone('America/Chicago')->subDays(7)->utc(),
            'thank_you_review'=>$e->ends_at->setTimezone('America/Chicago')->addDay()->setTime(10,0)->utc(),default=>throw new \InvalidArgumentException('Unsupported reminder.')};
    }
    public static function compose(string $key,#[\SensitiveParameter] array $source):array
    {
        [$e,$c,$f,$g,$net]=$source;$locale=$c->locale;if(!in_array($locale,['es','en'],true))ApprovedMail::fail('missing_recipient_locale');
        if($key==='balance_reminder'&&!in_array('checkout.create',$g->scopes,true))ApprovedMail::fail('payment_link_scope_required');
        $balance=max(0,$f->total_cents-$net);if($balance>0&&$f->balance_due_at===null)ApprovedMail::fail('balance_due_date_required');
        $money=fn(int $v)=>'$'.number_format(intdiv($v,100),0,'.',',').'.'.str_pad((string)($v%100),2,'0',STR_PAD_LEFT);
        $date=fn($d)=>MailDates::full($d,$locale);
        $link=Sandbox::origin().'/portal#token='.PortalTokenVault::open($g);
        $vars=['first_name'=>explode(' ',$c->name)[0],'event_date'=>$date($e->starts_at),'subject_date'=>MailDates::subject($e->starts_at,$locale),'address'=>$e->address,'package'=>implode(' + ',array_column($f->lines_snapshot,'name')),
            'package_price'=>$money($f->subtotal_cents),'travel_fee'=>$money($f->travel_fee_cents),'subtotal'=>$money($f->subtotal_cents+$f->travel_fee_cents),'discount_total'=>$money($f->discount_cents),
            'total'=>$money($f->total_cents),'deposit'=>$money($f->deposit_target_cents),'paid'=>$money(max(0,$net)),'balance'=>$money($balance),'has_balance'=>$balance>0,
            'due'=>$f->balance_due_at===null?'':MailDates::due($f->balance_due_at,$locale),
            'balance_due'=>$balance>0?($locale==='es'?' · vence ':' · due ').$date($f->balance_due_at):'',
            'link'=>$link,'review_link'=>config('waoo.mail_review_url')];
        $totals=[['label'=>$locale==='es'?'Paquete':'Package','amount'=>$vars['package_price']],['label'=>$locale==='es'?'Costo de transporte':'Travel fee','amount'=>$vars['travel_fee']]];
        foreach($f->discounts_snapshot??[] as $d)$totals[]=['label'=>$d['label'],'amount'=>'−'.$money($d['amount_cents']),'discount'=>true];$totals[]=['label'=>'Total','amount'=>$vars['total'],'strong'=>true];
        $rendered=ApprovedMail::render($key,$locale,$vars,$totals);$recipient=mb_strtolower(trim((string)$c->email));if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))ApprovedMail::fail('recipient_required');
        $fingerprint=MutationKey::hash(['event_version'=>$e->version,'customer_version'=>$c->version,'finance_id'=>$f->id,'recipient'=>$recipient,'rendered'=>$rendered,'grant_id'=>$g->id]);
        return [...$rendered,'recipient'=>$recipient,'source_sha256'=>$fingerprint];
    }
}
