<?php
namespace App\Services\Imports;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Contract,Customer,Event,ImportBatch,ImportFile,ImportedLedgerLine,ImportedProjectLink,ImportedProjectRevision,ImportRow,ImportServiceMapping,LedgerEntry,PriceVersion,ServiceVariant,User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Records\{SaveCustomer,SaveEventDraft};
use Illuminate\Support\Facades\DB;
final class CommitHoneyBookEvent
{
    public function execute(User $actor,int $batchId,int $rowId,#[\SensitiveParameter] array $input,string $key):ImportedProjectLink
    {
        if(array_key_exists('resync',$input))return app(ResyncHoneyBookMoney::class)->execute($actor,$batchId,$rowId,$input,$key);
        $actor=ImportInput::actor($actor);if(is_string($input['source_difference_note']??null))$input['source_difference_note']=trim($input['source_difference_note']);$d=ImportInput::input($input,[
            'expected_batch_version'=>'required|integer|min:1','expected_row_version'=>'required|integer|min:1','expected_preview_sha256'=>'required|string|regex:/^[a-f0-9]{64}$/D',
            'source_confirmed'=>'required|accepted','source_evidence_reviewed'=>'required|accepted','setup_minutes'=>'required|integer|min:0|max:720','breakdown_minutes'=>'required|integer|min:0|max:720',
            'services'=>'required|array|min:1|max:10','services.*'=>'array:price_version_id,expected_variant_version,characters','services.*.price_version_id'=>'required|integer|distinct|exists:price_versions,id',
            'services.*.expected_variant_version'=>'required|integer|min:1','services.*.characters'=>'sometimes|array|max:20','services.*.characters.*'=>'array:character_id,quantity,outfit,note',
            'services.*.characters.*.character_id'=>'required|integer|exists:characters,id','services.*.characters.*.quantity'=>'required|integer|min:1|max:20','services.*.characters.*.outfit'=>'sometimes|nullable|string|max:60','services.*.characters.*.note'=>'sometimes|nullable|string|max:200',
            'customer_id'=>'sometimes|integer|min:1','expected_customer_version'=>'required_with:customer_id|integer|min:1','create_separate_customer'=>'sometimes|accepted','new_customer_locale'=>'required_without:customer_id|in:es,en',
            'source_difference_accepted'=>'sometimes|boolean','source_difference_note'=>'sometimes|nullable|string|min:5|max:1000','remember_mapping'=>'sometimes|boolean','expected_import_version'=>'sometimes|integer|min:1','expected_event_version'=>'sometimes|integer|min:1']);
        if(isset($d['customer_id'])===isset($d['create_separate_customer']))ImportInput::fail('customer','choose_existing_or_explicit_new');
        foreach(['expected_batch_version','expected_row_version','setup_minutes','breakdown_minutes','customer_id','expected_customer_version','expected_import_version','expected_event_version'] as $f)if(isset($d[$f]))$d[$f]=(int)$d[$f];
        foreach($d['services'] as &$s){$s['price_version_id']=(int)$s['price_version_id'];$s['expected_variant_version']=(int)$s['expected_variant_version'];if(isset($s['characters']))foreach($s['characters'] as &$c){$c['character_id']=(int)$c['character_id'];$c['quantity']=(int)$c['quantity'];}unset($c);}unset($s);
        $d['source_confirmed']=true;$d['source_evidence_reviewed']=true;if(isset($d['create_separate_customer']))$d['create_separate_customer']=true;$d['remember_mapping']=(bool)($d['remember_mapping']??false);
        $hash=MutationKey::hash($d);$scope="owner:{$actor->id}:import:{$batchId}:row:{$rowId}:commit";
        return ImportPersistence::transaction(function()use($actor,$batchId,$rowId,$d,$hash,$scope,$key){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();ImportInput::lock();ImportInput::actor($actor);
            $b=ImportBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();
            if($receipt=MutationKey::find($scope,$key,$hash))return ImportedProjectLink::findOrFail($receipt->result_id);
            RecordWriteRejected::unless(in_array($b->status,['prepared','needs_review','partially_committed'],true)&&$b->version===$d['expected_batch_version']&&($b->profile['source_format']??null)==='honeybook_future_v1',409,'El lote cambió o no usa el formato futuro aprobado.');
            $row=ImportRow::whereKey($rowId)->where('import_batch_id',$batchId)->firstOrFail();$n=$row->normalized;$s=$row->source;
            RecordWriteRejected::unless($row->version===$d['expected_row_version']&&hash_equals($row->preview_sha256,$d['expected_preview_sha256'])&&$row->status!=='skipped'
                &&array_diff(array_column($row->errors,'code'),['owner_status_review_required','package_equation_mismatch'])===[]&&($n['source_format']??null)==='honeybook_future_v1',409,'La fila requiere revisar datos, importes u horario antes de importar.');
            $hasDifference=in_array('package_equation_mismatch',array_column($row->errors,'code'),true);
            if($hasDifference&&(!(bool)($d['source_difference_accepted']??false)||mb_strlen(trim($d['source_difference_note']??''))<5))ImportInput::fail('source_difference_note','explicit_source_difference_acceptance_required');
            if(!$hasDifference&&(array_key_exists('source_difference_accepted',$d)||array_key_exists('source_difference_note',$d)))ImportInput::fail('source_difference_note','source_difference_not_present');
            RecordWriteRejected::unless(\Carbon\CarbonImmutable::parse($n['starts_at'])->isFuture(),409,'El evento de origen ya no es futuro.');
            $businessSelections=array_map(function($x){unset($x['expected_variant_version']);return $x;},$d['services']);
            $domainIntent=['source_sha256'=>$n['source_sha256'],'services'=>$businessSelections,'customer'=>isset($d['customer_id'])?['id'=>$d['customer_id']]:['new'=>true,'locale'=>$d['new_customer_locale']],
                'setup_minutes'=>$d['setup_minutes'],'breakdown_minutes'=>$d['breakdown_minutes'],...($hasDifference?['source_difference_note'=>$d['source_difference_note']]:[])];$domainHash=MutationKey::hash($domainIntent);
            // Singleton serializes creators. Read parent ID before event -> linkage locks, never linkage -> event.
            $existing=ImportedProjectLink::where('project_key',$row->source_key)->first();$event=null;
            if($existing){$event=Event::whereKey($existing->event_id)->lockForUpdate()->firstOrFail();$existing=ImportedProjectLink::whereKey($existing->id)->lockForUpdate()->firstOrFail();
                if(hash_equals($existing->domain_sha256,$domainHash)){MutationKey::store($scope,$key,$hash,'imported_project_link',$existing->id);return $existing;}
                RecordWriteRejected::unless(($d['expected_import_version']??0)===$existing->version&&($d['expected_event_version']??0)===$event->version&&$event->status==='draft'
                    &&!$event->reservations()->exists()&&!Contract::where('event_id',$event->id)->exists()&&!LedgerEntry::where('event_id',$event->id)->exists(),409,'El proyecto ya fue importado; solo una revisión explícita de su borrador puede cambiarlo.');
            }
            app(ImportArtifacts::class)->read(ImportFile::where('import_batch_id',$b->id)->where('role','projects')->sole());
            // Displayed operational versions are checked under sorted catalog locks. Price is historical source, not these prices.
            $prices=PriceVersion::whereIn('id',array_column($d['services'],'price_version_id'))->get()->keyBy('id');
            $variants=ServiceVariant::whereIn('id',$prices->pluck('service_variant_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach($d['services'] as $selection){$v=$variants[$prices[$selection['price_version_id']]->service_variant_id];RecordWriteRejected::unless((int)$v->version===$selection['expected_variant_version']&&$v->status==='active',409,'El paquete cambió o no está listo para asignar.');}
            $customer=null;
            if(isset($d['customer_id'])){$customer=Customer::whereKey($d['customer_id'])->lockForUpdate()->firstOrFail();RecordWriteRejected::unless($customer->version===$d['expected_customer_version'],409,'La ficha del cliente cambió.');}
            elseif($existing)$customer=Customer::findOrFail($existing->customer_id);
            $link=Audit::metadataOnly(function()use($actor,$d,$b,$row,$n,$s,$existing,$event,$customer,$domainHash,$hasDifference){
                if(!$customer)$customer=app(SaveCustomer::class)->execute($actor,['name'=>$n['customer_name'],'email'=>$n['customer_email'],'phone'=>$n['customer_phone'],'locale'=>$d['new_customer_locale']]);
                $selections=array_map(function($x){unset($x['expected_variant_version']);return $x;},$d['services']);
                $draft=['customer_id'=>$customer->id,'title'=>$s['project_name'],'starts_at'=>$n['starts_at'],'ends_at'=>$n['ends_at'],'timezone'=>'America/Chicago',
                    'address'=>$s['event_address'],'postal_code'=>$s['event_zip'],'setup_minutes'=>$d['setup_minutes'],'breakdown_minutes'=>$d['breakdown_minutes'],
                    'travel_fee_cents'=>$n['travel_cents'],'travel_fee_override_note'=>'Historical HoneyBook source travel amount reviewed by owner.','services'=>$selections];
                if($event)$draft['expected_version']=$event->version;
                $event=app(SaveEventDraft::class)->execute($actor,$draft,$event?->id);
                if(!$existing){$event->forceFill(['responsible_system'=>'honeybook'])->save();} // Never flip an existing event during resync.
                $event->forceFill(['travel_fee_evidence'=>['source'=>'honeybook','amount_cents'=>$n['travel_cents'],'source_sha256'=>$n['source_sha256'],'import_row_id'=>$row->id,'recorded_by'=>$actor->id]])->save();
                RecordWriteRejected::unless($event->services->every(fn($service)=>$service->operational_ready&&!empty($service->requirements_snapshot)),422,'Completa los personajes y requisitos del paquete antes de importar.');
                $link=$existing??new ImportedProjectLink(['project_key'=>$row->source_key,'event_id'=>$event->id,'customer_id'=>$customer->id,'version'=>1]);
                if($existing)$link->version++;$link->domain_sha256=$domainHash;$link->customer_id=$customer->id;$link->save();
                $mapping=['intent'=>$d['services'],'customer_intent'=>isset($d['customer_id'])?['id'=>$d['customer_id']]:['new'=>true,'locale'=>$d['new_customer_locale']],
                    'operations_sha256'=>ImportedAgreement::operations($event),'setup_minutes'=>$d['setup_minutes'],'breakdown_minutes'=>$d['breakdown_minutes']];
                $mapping['payment_label_map_version']=HoneyBookPaymentLabels::VERSION;
                if($hasDifference)$mapping['source_difference']=['policy_version'=>HoneyBookSourceDifference::VERSION,'code'=>'package_equation_mismatch','difference_cents'=>HoneyBookSourceDifference::delta($n),'note'=>$d['source_difference_note'],'accepted_by'=>$actor->id,'accepted_at'=>now()->toIso8601String()];
                $revision=ImportedProjectRevision::create(['imported_project_link_id'=>$link->id,'revision'=>$link->version,'import_batch_id'=>$b->id,'import_row_id'=>$row->id,'event_version'=>$event->version,
                    'source_snapshot'=>$s,'normalized'=>$n,'mapping'=>$mapping,'source_sha256'=>$n['source_sha256'],'file_sha256'=>ImportFile::where('import_batch_id',$b->id)->value('sha256'),'created_by'=>$actor->id]);
                foreach($n['payments'] as $p)ImportedLedgerLine::create(['imported_project_revision_id'=>$revision->id,'source_position'=>$p['source_position'],'amount_cents'=>$p['amount_cents'],'received_date'=>$p['received_date'],'method'=>$p['method'],'source'=>'honeybook']);
                $link->current_revision_id=$revision->id;$link->save();return $link;
            });
            if($d['remember_mapping']){$mapping=ImportServiceMapping::where('source_key',HoneyBookMappingKey::forSource($s))->first()??new ImportServiceMapping(['source_key'=>HoneyBookMappingKey::forSource($s),'version'=>0]);$mapping->version++;$mapping->selection=$d['services'];$mapping->updated_by=$actor->id;$mapping->save();}
            $b->forceFill(['version'=>$b->version+1,'status'=>'partially_committed'])->save();
            Audit::recordFor($actor,'import.project_committed',$link,[],['import_batch_id'=>$b->id,'imported_revision_id'=>$link->current_revision_id,'source_sha256'=>$n['source_sha256'],'responsible_system'=>Event::whereKey($link->event_id)->value('responsible_system')]);
            MutationKey::store($scope,$key,$hash,'imported_project_link',$link->id);return $link;
        },3);
    }
}
