<?php
namespace App\Services\Imports;
use App\Models\{Customer,ImportBatch,ImportPreviewVersion,ImportRow,User};
use App\Services\Finance\MutationKey;
use Carbon\CarbonImmutable;
final class PersistImportPreview
{
    public static function fingerprint(array $files,array $profile):string
    {
        return MutationKey::hash(['files'=>array_map(fn($f)=>$f['sha256'],$files),'profile'=>$profile]);
    }
    public static function execute(ImportBatch $batch,User $actor,array $files,array $profile):void
    {
        $preview=app(ImportPreview::class)->build($files,$profile,CarbonImmutable::now());
        // The importer singleton is held by stage/profile. Only server-known projects may
        // review past source money. Keep the original past reason; never unskip cancellations.
        if(($profile['source_format']??null)==='honeybook_future_v1'){
            $known=\App\Models\ImportedProjectLink::whereIn('project_key',array_filter(array_column($preview['rows'],'source_key')))->sharedLock()->pluck('id','project_key');
            foreach($preview['rows'] as &$r){
                if($r['status']==='skipped'&&$r['skip_reason']==='past'&&$known->has($r['source_key'])){
                    $preview['reconciliation']['counts']['skipped']--;
                    $codes=array_column($r['errors'],'code');
                    $r['status']=array_intersect($codes,['package_equation_mismatch','balance_equation_mismatch','payments_equation_mismatch'])?'conflict':($codes?'needs_mapping':'ready');
                    $preview['reconciliation']['counts'][$r['status']]++;
                    if($r['status']==='conflict')foreach($preview['reconciliation']['totals'] as &$total)$total['conflict_count']++;
                    unset($total);
                }
            }unset($r);
        }
        // Only exact contact hints; deliberately no name-only match, merge or customer write.
        $emails=[];$phones=[];
        foreach($preview['rows'] as $r){if(filter_var($r['normalized']['customer_email']??'',FILTER_VALIDATE_EMAIL))$emails[]=mb_strtolower($r['normalized']['customer_email']);if(filled($r['normalized']['customer_phone']??null)&&mb_strlen($r['normalized']['customer_phone'])<=30)$phones[]=$r['normalized']['customer_phone'];}
        $contacts=($emails!==[]||$phones!==[])?Customer::query()->where(function($q)use($emails,$phones){if($emails!==[])$q->whereIn('email',array_unique($emails));if($phones!==[])$q->orWhereIn('phone',array_unique($phones));})->limit(1001)->get(['id','email','phone','version']):collect();
        $byEmail=[];$byPhone=[];
        foreach($contacts->take(1000) as $c){$hint=['customer_id'=>$c->id,'expected_version'=>$c->version,'requires_explicit_choice'=>true];if(filled($c->email))$byEmail[mb_strtolower($c->email)][]=$hint;if(filled($c->phone))$byPhone[$c->phone][]=$hint;}
        foreach($preview['rows'] as &$row){$row['duplicate_hints']=array_values(collect([...($byEmail[mb_strtolower($row['normalized']['customer_email']??'')]??[]),...($byPhone[$row['normalized']['customer_phone']??'']??[])])->unique('customer_id')->all());}unset($row);
        $preview['reconciliation']['hints_truncated']=$contacts->count()>1000;
        $hash=MutationKey::hash(['profile'=>$profile,...$preview]);
        ImportRow::where('import_batch_id',$batch->id)->delete();
        foreach(array_chunk($preview['rows'],100) as $chunk){$insert=[];foreach($chunk as $row){$insert[]=[...$row,'import_batch_id'=>$batch->id,'version'=>$batch->version,'skip_reason'=>$row['skip_reason']??null,
            'source'=>json_encode($row['source'],JSON_THROW_ON_ERROR),'normalized'=>json_encode($row['normalized'],JSON_THROW_ON_ERROR),'errors'=>json_encode($row['errors'],JSON_THROW_ON_ERROR),
            'duplicate_hints'=>json_encode($row['duplicate_hints'],JSON_THROW_ON_ERROR),'preview_sha256'=>MutationKey::hash($row),'created_at'=>now(),'updated_at'=>now()];}ImportRow::insert($insert);}
        $counts=$preview['reconciliation']['counts'];
        $batch->forceFill(['profile'=>$profile,'profile_sha256'=>MutationKey::hash($profile),'preview_sha256'=>$hash,'reconciliation'=>$preview['reconciliation'],
            'status'=>($counts['needs_mapping']||$counts['conflict'])?'needs_review':'prepared','active_fingerprint'=>self::fingerprint($files,$profile)])->save();
        ImportPreviewVersion::create(['import_batch_id'=>$batch->id,'version'=>$batch->version,'profile_sha256'=>$batch->profile_sha256,'preview_sha256'=>$hash,'counts'=>$counts,'created_by'=>$actor->id]);
    }
}
