<?php
namespace App\Services\Quotes;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, PortalGrant, Quote, QuoteRevision};
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Records\RecordInput;
use Illuminate\Validation\ValidationException;

final class QuoteProtocol
{
    public static function input(array $input,array $rules): array
    {
        foreach (array_diff(array_keys($input),array_keys($rules)) as $key) throw ValidationException::withMessages([$key=>'Este campo no se acepta.']);
        return RecordInput::validate($input,$rules);
    }
    public static function portalGate(): void { Sandbox::require('sandbox_quotes'); Sandbox::require(); Sandbox::require('sandbox_documents'); }
    public static function revisionHash(QuoteRevision $r): string
    {
        return MutationKey::hash($r->only(['quote_id','revision','event_version','currency','customer_snapshot','event_snapshot','lines_snapshot','travel_fee_evidence',
            'discounts_snapshot','subtotal_cents','travel_fee_cents','discount_cents','total_cents','deposit_target_cents','valid_until']));
    }
    public static function lockGrants(int $quoteId): void
    {
        PortalGrant::whereIn('quote_revision_id',QuoteRevision::where('quote_id',$quoteId)->select('id'))->orderBy('id')->lockForUpdate()->get();
    }
    public static function assertSource(Event $e,Quote $q,QuoteRevision $r): void
    {
        RecordWriteRejected::unless($e->status==='draft' && $e->customer_id===$q->customer_id && $e->version===$r->event_version
            && $q->current_revision===$r->revision && (int)($r->event_snapshot['billing_company_version']??0)===(int)$e->billing_company_version,409,'El borrador o la cotización cambió. Revisa una nueva versión.');
    }
    public static function consents(string $locale): array
    {
        $c=config('waoo.quote_acceptance_consent',[]); $out=[];
        foreach (['accepted','rejected'] as $kind) {
            $text=$c[$locale][$kind]??null; $version=$c['version']??null;
            if (!is_string($version)||trim($version)===''||strlen($version)>100||!is_string($text)||trim($text)===''||strlen($text)>20000) {
                throw ValidationException::withMessages(['consent'=>'Configura primero el texto y la versión aprobados para aceptar y rechazar cotizaciones.']);
            }
            $out[$kind]=['version'=>$version,'text'=>$text,'sha256'=>hash('sha256',$text)];
        }
        return $out;
    }
    public static function payload(Quote $q,QuoteRevision $r): array
    {
        return ['number'=>$q->number,'revision'=>$r->revision,'currency'=>'USD','locale'=>$q->locale,'valid_until'=>$r->valid_until->toIso8601String(),
            'customer_name'=>$r->customer_snapshot['name'],'event'=>$r->event_snapshot,
            'lines'=>array_map(fn($l)=>['name'=>$l['name'],'long_description'=>$l['long_description']??null,'duration_minutes'=>$l['duration_minutes'],
                'quantity'=>1,'amount_cents'=>$l['amount_cents'],'characters'=>array_map(fn($c)=>['name'=>\App\Services\Records\CharacterLabel::fromSnapshot($c),
                    'quantity'=>$c['quantity']],$l['characters'])],$r->lines_snapshot),
            'discounts'=>array_map(fn($d)=>['label'=>$d['label'],'amount_cents'=>$d['amount_cents']],$r->discounts_snapshot),
            ...$r->only(['subtotal_cents','travel_fee_cents','discount_cents','total_cents','deposit_target_cents'])];
    }
}
