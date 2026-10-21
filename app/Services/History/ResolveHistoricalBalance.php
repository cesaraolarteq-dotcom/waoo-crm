<?php
namespace App\Services\History;
use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoricalBalanceResolution,HistoricalEvent,HistoricalEventRevision,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Imports\{ImportInput,ImportPersistence};
final class ResolveHistoricalBalance
{
    public function execute(User $actor,int $historyId,#[\SensitiveParameter] array $input,string $key):array {
        HistoryAccess::actor($actor);
        $d=ImportInput::input($input,['expected_history_version'=>'required|integer|min:1','expected_revision_id'=>'required|integer|min:1',
            'expected_resolution_version'=>'required|integer|min:0','expected_source_sha256'=>'required|string|regex:/^[a-f0-9]{64}$/D',
            'kind'=>'required|in:paid_outside_honeybook,duplicate_source_file,cancelled_no_balance,pending_reschedule','owner_note'=>'required|string|min:10|max:2000']);
        if(mb_strlen(trim($d['owner_note']))<10)ImportInput::fail('owner_note','meaningful_note_required');
        foreach(['expected_history_version','expected_revision_id','expected_resolution_version'] as $f)$d[$f]=(int)$d[$f];
        $hash=MutationKey::hash($d);$scope='owner:'.$actor->id.':history:'.$historyId.':balance';
        return ImportPersistence::transaction(function()use($actor,$historyId,$d,$key,$hash,$scope){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();ImportInput::lock();HistoryAccess::actor($actor);
            if($receipt=MutationKey::find($scope,$key,$hash,true)){
                $record=HistoricalBalanceResolution::findOrFail($receipt->result_id);RecordWriteRejected::unless($record->historical_event_id===$historyId,409,'La declaración requiere revisión.');return HistoricalBalanceEvidence::read($record)['result'];
            }
            $event=HistoricalEvent::whereKey($historyId)->lockForUpdate()->firstOrFail();$revision=HistoricalEventRevision::whereKey($event->current_revision_id)->lockForUpdate()->firstOrFail();
            $p=HistoricalIntegrity::revision($event,$revision);$last=HistoricalBalanceResolution::where('historical_event_id',$historyId)->orderByDesc('version')->lockForUpdate()->first();
            if($last)HistoricalBalanceEvidence::read($last);
            RecordWriteRejected::unless($event->version===$d['expected_history_version']&&$revision->id===$d['expected_revision_id']
                &&hash_equals($revision->source_sha256,$d['expected_source_sha256'])&&($last?->version??0)===$d['expected_resolution_version'],409,'El historial o la resolución cambió. Revisa antes de confirmar.');
            RecordWriteRejected::unless(($p['facts']['record_kind']??'event')==='event'&&($p['facts']['currency']??null)==='USD'&&($p['facts']['balance_cents']??0)>0,409,'Esta fiesta no tiene un saldo positivo de origen para resolver.');
            $version=($last?->version??0)+1;
            $result=['history_id'=>$historyId,'version'=>$version,'kind'=>$d['kind'],'source_revision_id'=>$revision->id,'bank_verified'=>false];
            $payload=['schema_version'=>1,'history_id'=>$historyId,'version'=>$version,'created_by'=>$actor->id,'input'=>$d,
                'source_balance_cents'=>$p['facts']['balance_cents'],'previous_resolution_id'=>$last?->id,'result'=>$result];
            $record=HistoricalBalanceResolution::create(['historical_event_id'=>$historyId,'version'=>$version,'payload'=>$payload,'sha256'=>MutationKey::hash($payload),'created_by'=>$actor->id]);
            Audit::recordFor($actor,'history.balance_resolved',$record,[],['history_id'=>$historyId,'version'=>$version,'kind'=>$d['kind']]);
            MutationKey::store($scope,$key,$hash,'historical_balance_resolution',$record->id);return $result;
        });
    }
}
