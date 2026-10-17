<?php
namespace App\Services\Quotes;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, QuoteConversion, QuoteDecision, QuotePresentation, QuoteRevision};
use App\Services\Finance\MutationKey;

final class QuoteAgreement
{
    public static function forEvent(Event $event): ?array
    {
        $link=QuoteConversion::where('event_id',$event->id)->first();
        return $link?self::attributes($event,QuoteRevision::findOrFail($link->quote_revision_id),QuoteDecision::findOrFail($link->quote_decision_id)):null;
    }
    public static function attributes(Event $event,QuoteRevision $r,QuoteDecision $decision): array
    {
        $p=QuotePresentation::findOrFail($decision->quote_presentation_id);
        RecordWriteRejected::unless($decision->outcome==='accepted'&&$decision->quote_revision_id===$r->id&&$p->quote_revision_id===$r->id
            &&hash_equals($p->revision_sha256,QuoteProtocol::revisionHash($r))&&hash_equals($decision->snapshot_sha256,$p->snapshot_sha256)
            &&hash_equals($decision->pdf_sha256,$p->pdf_sha256),409,'No se pudo verificar el acuerdo aceptado.');
        app(QuoteArtifacts::class)->read($p);
        $services=$event->services()->with('characters')->get(); $actual=[]; $serviceIds=[];
        foreach($services as $s){
            $characters=$s->characters->map(fn($c)=>['character_id'=>$c->character_id,'quantity'=>(int)$c->quantity,'outfit'=>$c->outfit])->all();
            if($characters===[])foreach($s->requirements_snapshot as $req)if($req['kind']==='costume')$characters[]=['character_id'=>$req['character_id'],'quantity'=>(int)$req['quantity'],'outfit'=>$req['outfit']??null];
            $actual[]=['price_version_id'=>$s->price_version_id,'duration_minutes'=>$s->duration_minutes,'requirements_snapshot'=>$s->requirements_snapshot,'characters'=>$characters];
            $serviceIds[$s->price_version_id]=$s->id;
        }
        $expected=array_map(fn($l)=>['price_version_id'=>$l['price_version_id'],'duration_minutes'=>$l['duration_minutes'],'requirements_snapshot'=>$l['requirements_snapshot'],
            'characters'=>array_map(fn($c)=>['character_id'=>$c['character_id'],'quantity'=>$c['quantity'],'outfit'=>$c['outfit']??null],$l['characters'])],$r->lines_snapshot);
        $normalize=function(array $lines):array{
            foreach($lines as &$line){usort($line['requirements_snapshot'],fn($a,$b)=>strcmp(MutationKey::hash($a),MutationKey::hash($b)));usort($line['characters'],fn($a,$b)=>strcmp(MutationKey::hash($a),MutationKey::hash($b)));}
            unset($line);usort($lines,fn($a,$b)=>$a['price_version_id']<=>$b['price_version_id']);return $lines;
        };
        RecordWriteRejected::unless($event->customer_id===(int)$r->customer_snapshot['id']&&$event->travel_fee_cents===$r->travel_fee_cents
            &&$event->starts_at->toIso8601String()===$r->event_snapshot['starts_at']&&$event->ends_at->toIso8601String()===$r->event_snapshot['ends_at']
            &&$event->timezone===$r->event_snapshot['timezone']&&MutationKey::hash($normalize($actual))===MutationKey::hash($normalize($expected)),409,'Los servicios, horario o importes cambiaron; requiere una nueva cotización o el flujo de cambios.');
        return ['quote_revision_id'=>$r->id,'currency'=>'USD','lines_snapshot'=>array_map(fn($l)=>[...$l,'event_service_id'=>$serviceIds[$l['price_version_id']]],$r->lines_snapshot),
            ...$r->only(['subtotal_cents','travel_fee_cents','discount_cents','discounts_snapshot','total_cents','deposit_target_cents']),
            'tax_cents'=>0,'customer_fee_cents'=>0,'balance_due_at'=>$event->starts_at->subHours(24)];
    }
}
