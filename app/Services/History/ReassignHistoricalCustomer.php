<?php
namespace App\Services\History;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer,HistoricalCustomerRelink,HistoricalEvent,HistoricalEventRevision,User};
use App\Services\Audit;
use App\Services\Finance\{MutationKey,Sandbox};
use App\Services\Imports\{ImportInput,ImportPersistence};
use App\Services\Records\SaveCustomer;
final class ReassignHistoricalCustomer
{
    public function execute(User $actor,int $historyId,#[\SensitiveParameter] array $input,string $key): array {
        HistoryAccess::actor($actor);Sandbox::require('sandbox_history_verification');
        $d=ImportInput::input($input,[
            'expected_history_version'=>'required|integer|min:1','expected_revision_id'=>'required|integer|min:1',
            'expected_source_sha256'=>'required|string|regex:/^[a-f0-9]{64}$/D', 'owner_reason'=>'required|string|min:10|max:2000',
            'target'=>'required|array:mode,id,expected_version,name,email,phone,locale', 'target.mode'=>'required|in:existing,new',
            'target.id'=>'required_if:target.mode,existing|prohibited_unless:target.mode,existing|integer|min:1',
            'target.expected_version'=>'required_if:target.mode,existing|prohibited_unless:target.mode,existing|integer|min:1',
            'target.name'=>'required_if:target.mode,new|prohibited_unless:target.mode,new|string|max:255',
            'target.email'=>'sometimes|nullable|prohibited_unless:target.mode,new|email|max:255',
            'target.phone'=>'sometimes|nullable|prohibited_unless:target.mode,new|string|max:40',
            'target.locale'=>'required_if:target.mode,new|prohibited_unless:target.mode,new|in:es,en']);
        RecordWriteRejected::unless(mb_strlen(trim($d['owner_reason']))>=10,422,'Escribe el motivo de la corrección.');
        foreach(['expected_history_version','expected_revision_id'] as $f)$d[$f]=(int)$d[$f];
        foreach(['id','expected_version'] as $f)if(isset($d['target'][$f]))$d['target'][$f]=(int)$d['target'][$f];
        $hash=MutationKey::hash($d);$scope='owner:'.$actor->id.':history:'.$historyId.':reassign';
        return ImportPersistence::transaction(function()use($actor,$historyId,$d,$key,$hash,$scope){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();ImportInput::lock();
            HistoryAccess::actor($actor);Sandbox::require('sandbox_history_verification');
            if($r=MutationKey::find($scope,$key,$hash,true)){ $record=HistoricalCustomerRelink::findOrFail($r->result_id);$e=HistoricalCustomerBinding::evidence($record);RecordWriteRejected::unless($record->historical_event_id===$historyId&&MutationKey::hash($e['input'])===$hash,409,'La corrección requiere revisión.');return $e['result']; }
            $history=HistoricalEvent::whereKey($historyId)->lockForUpdate()->firstOrFail();
            $old=HistoricalEventRevision::whereKey($history->current_revision_id)->lockForUpdate()->firstOrFail();
            $p=HistoricalIntegrity::revision($history,$old);
            RecordWriteRejected::unless($history->version===$d['expected_history_version']&&$old->id===$d['expected_revision_id']
                &&hash_equals($old->source_sha256,$d['expected_source_sha256']),409,'El historial cambió. Revisa antes de corregir.');
            $target=$d['target'];
            if($target['mode']==='existing'){
                $customer=Customer::whereKey($target['id'])->lockForUpdate()->firstOrFail();
                RecordWriteRejected::unless($customer->version===$target['expected_version'],409,'La clienta cambió. Revisa de nuevo.');
            }else{
                unset($target['mode']);$customer=Audit::metadataOnly(fn()=>app(SaveCustomer::class)->execute($actor,$target));
            }
            RecordWriteRejected::unless($customer->id!==$history->customer_id,409,'El historial ya pertenece a esa clienta.');
            $before=$history->customer_id;$version=$history->version+1;
            $payload=[...$p,'customer_id'=>$customer->id,'version'=>$version,'created_by'=>$actor->id,'previous_revision_id'=>$old->id,
                'customer_correction'=>['previous_customer_id'=>$before,'owner_reason'=>$d['owner_reason']]];
            $new=HistoricalEventRevision::create(['historical_event_id'=>$historyId,'customer_id'=>$customer->id,'version'=>$version,
                'source_sha256'=>$old->source_sha256,'facts_sha256'=>$old->facts_sha256,'payload'=>$payload,'payload_sha256'=>MutationKey::hash($payload),'created_by'=>$actor->id]);
            $history->forceFill(['customer_id'=>$customer->id,'version'=>$version,'current_revision_id'=>$new->id])->save();
            $result=['history_id'=>$historyId,'history_version'=>$version,'revision_id'=>$new->id,'customer_id'=>$customer->id,'previous_customer_id'=>$before];
            $evidence=['history_id'=>$historyId,'version'=>$version,'created_by'=>$actor->id,'contact_id'=>$p['source']['contact_id'],
                'input'=>$d,'previous_revision_id'=>$old->id,'result'=>$result];
            $record=HistoricalCustomerRelink::create(['historical_event_id'=>$historyId,'revision_id'=>$new->id,'version'=>$version,
                'contact_key'=>$p['source']['contact_id']===''?null:hash('sha256',$p['source']['contact_id']),
                'payload'=>$evidence,'sha256'=>MutationKey::hash($evidence),'created_by'=>$actor->id]);
            Audit::recordFor($actor,'history.customer_reassigned',$record,[],['history_id'=>$historyId,'revision_id'=>$new->id,'previous_customer_id'=>$before,'customer_id'=>$customer->id]);
            MutationKey::store($scope,$key,$hash,'historical_customer_relink',$record->id);return $result;
        });
    }
}
