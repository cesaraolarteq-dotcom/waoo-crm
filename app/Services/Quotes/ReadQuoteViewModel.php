<?php
namespace App\Services\Quotes;

use App\Models\QuoteDecision;
use App\Services\Portal\PortalContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReadQuoteViewModel
{
    public function execute(PortalContext $client, mixed $lang=null): array
    {
        return $this->read($client,$lang)['view_model'];
    }

    public function read(PortalContext $client, mixed $lang=null): array
    {
        if($lang!==null&&!in_array($lang,['es','en'],true))throw ValidationException::withMessages(['lang'=>'Selecciona es o en.']);
        return DB::transaction(function()use($client,$lang){
            [$g,$e,$q,$r,$p]=QuotePortalAccess::resolve($client,'quote.read');
            $locale=$lang??$p->payload['locale'];$payload=$p->payload;
            $status=$q->effectiveStatus();
            $state=$q->current_revision!==$r->revision?'superseded':match($status){
                'sent'=>$e->status==='draft'&&$e->version===$r->event_version?'open':'superseded',
                'accepted','rejected','expired'=>$status,
                default=>'superseded',
            };
            $consent=$p->consents['accepted'];
            $approved=is_string($consent['version']??null)&&trim($consent['version'])!==''&&is_string($consent['text']??null)&&trim($consent['text'])!=='';
            $canDecide=$state==='open'&&$approved&&!$client->adminAuthenticated;
            $date=fn($v)=>CarbonImmutable::parse($v)->setTimezone('America/Chicago')->locale($locale)->translatedFormat('D, M j, Y · g:i A T');
            $money=static fn(int $c)=>'$'.number_format(intdiv($c,100),0,'.',',').'.'.str_pad((string)($c%100),2,'0',STR_PAD_LEFT);
            $name=$payload['customer_name'];
            $vm=['lang'=>$locale,'first_name'=>Str::before($name,' '),'test_mode'=>true,
                'lang_url'=>['es'=>'/portal/quote?lang=es','en'=>'/portal/quote?lang=en'],
                'quote'=>['number'=>$payload['number'],'revision'=>$payload['revision'],'state'=>$state,'valid_until'=>$date($payload['valid_until']),
                    'when'=>$date($payload['event']['starts_at']).' – '.$date($payload['event']['ends_at']),
                    'where'=>$payload['event']['address']??'',
                    'lines'=>array_map(fn($l)=>['name'=>$l['name'],'duration'=>$l['duration_minutes'].' min',
                        'characters'=>implode(', ',array_map(fn($c)=>$c['name'].($c['quantity']>1?' × '.$c['quantity']:''),$l['characters'])),
                        'amount'=>$money($l['amount_cents'])],$payload['lines']),
                    'travel'=>$money($payload['travel_fee_cents']),
                    'discounts'=>array_map(fn($d)=>['label'=>$d['label'],'amount'=>$money($d['amount_cents'])],$payload['discounts']),
                    'total'=>$money($payload['total_cents']),'deposit'=>$money($payload['deposit_target_cents']),
                    'pdf_url'=>$state==='superseded'?null:'/portal/api/quote/pdf'],
                'consent'=>['version'=>$approved?$consent['version']:null,'text'=>$approved?$consent['text']:null],
                'prefill'=>['name'=>$name,'email'=>''],
                'actions'=>['accept'=>$canDecide?'/portal/api/quote/accept':null,'reject'=>$canDecide?'/portal/api/quote/reject':null]];
            return ['quote'=>$payload,'snapshot_sha256'=>$p->snapshot_sha256,'pdf_sha256'=>$p->pdf_sha256,'consents'=>$p->consents,
                'status'=>$status,'can_decide'=>$canDecide,
                'decision'=>QuoteDecision::where('quote_revision_id',$r->id)->first()?->only(['outcome','decided_at']),
                'view_model'=>$vm];
        },3);
    }
}
