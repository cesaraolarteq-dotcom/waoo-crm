<?php
namespace App\Services\Quotes;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Contract, Event, FinancialRevision, LedgerEntry, Quote, QuoteConversion, QuoteDecision, QuoteRevision, User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\DB;

final class ConvertAcceptedQuote
{
    public function execute(User $actor,int $quoteId,array $input,string $key): FinancialRevision
    {
        $actor=QuoteInput::actor($actor);
        $data=QuoteProtocol::input($input,['expected_version'=>'required|integer|min:1','expected_revision'=>'required|integer|min:1',
            'expected_event_version'=>'required|integer|min:1','decision_id'=>'required|integer|min:1']);$data=array_map('intval',$data);
        $scope="owner:{$actor->id}:quote:{$quoteId}:convert";$hash=MutationKey::hash($data);$eventId=Quote::findOrFail($quoteId)->event_id;
        return DB::transaction(function()use($actor,$quoteId,$eventId,$data,$scope,$hash,$key){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();QuoteInput::actor($actor);
            $e=Event::whereKey($eventId)->lockForUpdate()->firstOrFail();$q=Quote::whereKey($quoteId)->lockForUpdate()->firstOrFail();
            if($receipt=MutationKey::find($scope,$key,$hash))return FinancialRevision::findOrFail($receipt->result_id);
            RecordWriteRejected::unless($q->status==='accepted'&&$q->version===$data['expected_version']&&$q->current_revision===$data['expected_revision']
                &&$e->status==='draft'&&$e->version===$data['expected_event_version'],409,'La cotización o el borrador cambió.');
            $r=QuoteRevision::where('quote_id',$quoteId)->where('revision',$q->current_revision)->firstOrFail();QuoteProtocol::assertSource($e,$q,$r);
            $d=QuoteDecision::whereKey($data['decision_id'])->where('quote_revision_id',$r->id)->where('outcome','accepted')->firstOrFail();
            // Current reads after the event lock: another owner may have committed while this actor waited.
            RecordWriteRejected::unless(!QuoteConversion::where('event_id',$eventId)->lockForUpdate()->first()&&!FinancialRevision::where('event_id',$eventId)->lockForUpdate()->first()
                &&!LedgerEntry::where('event_id',$eventId)->lockForUpdate()->first()&&!Contract::where('event_id',$eventId)->lockForUpdate()->first(),409,'El evento ya tiene un acuerdo o pagos. Requiere reconciliación, no una conversión automática.');
            $finance=FinancialRevision::create(['event_id'=>$eventId,'revision'=>1,'event_version'=>$e->version,'created_by'=>$actor->id,...QuoteAgreement::attributes($e,$r,$d)]);
            $link=QuoteConversion::create(['quote_id'=>$quoteId,'event_id'=>$eventId,'quote_revision_id'=>$r->id,'quote_decision_id'=>$d->id,'financial_revision_id'=>$finance->id,'created_by'=>$actor->id]);
            $q->forceFill(['version'=>$q->version+1])->save();
            Audit::recordFor($actor,'quote.converted',$link,[],['quote_id'=>$quoteId,'quote_decision_id'=>$d->id,'financial_revision_id'=>$finance->id,'total_cents'=>$finance->total_cents]);
            MutationKey::store($scope,$key,$hash,'financial_revision',$finance->id);return $finance;
        },3);
    }
}
