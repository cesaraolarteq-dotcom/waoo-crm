<?php
namespace App\Services\Imports;
use App\Exceptions\RecordWriteRejected;
use App\Models\{BookingEvidence,Event,ImportBatch,ImportedProjectLink,ImportedProjectRevision,ImportRow,User};
use Illuminate\Support\Facades\DB;
final class ReadImportCoverage
{
    public function execute(User $actor,int $batchId):array
    {
        ImportInput::actor($actor);
        return ImportPersistence::transaction(function()use($batchId){
            $b=ImportBatch::findOrFail($batchId);RecordWriteRejected::unless(!in_array($b->status,['discarded','cleanup_pending'],true),410,'El lote fue descartado.');
            $counts=['source_rows'=>0,'skipped'=>0,'unresolved'=>0,'unmapped'=>0,'outdated'=>0,'draft'=>0,'capacity_confirmed'=>0];$gaps=[];$acceptedDifferences=[];$totals=[];foreach(['package_cents','travel_cents','discount_cents','total_cents','paid_cents','balance_cents'] as $field)$totals[$field]=['source_sum_cents'=>0,'imported_sum_cents'=>0,'unknown_source_count'=>0,'matched_source_count'=>0];
            foreach(ImportRow::where('import_batch_id',$batchId)->get() as $row){$counts['source_rows']++;
                if($row->status==='skipped'){$counts['skipped']++;continue;}
                $reason=null;$n=$row->normalized;$matchingRevision=null;$link=$row->source_key?ImportedProjectLink::where('project_key',$row->source_key)->first():null;
                $currentRevision=$link?ImportedProjectRevision::findOrFail($link->current_revision_id):null;
                $differenceCovered=HoneyBookSourceDifference::covers($row,$currentRevision);
                if($differenceCovered)$acceptedDifferences[]=['row_id'=>$row->id,'revision_id'=>$currentRevision->id,'evidence'=>$currentRevision->mapping['source_difference']];
                if(array_diff(array_column($row->errors,'code'),$differenceCovered?['owner_status_review_required','package_equation_mismatch']:['owner_status_review_required'])!==[]||($n['source_format']??null)!=='honeybook_future_v1')$reason='unresolved';
                elseif(!$link)$reason='unmapped';
                else{$r=ImportedProjectRevision::findOrFail($link->current_revision_id);$event=Event::findOrFail($link->event_id);if(hash_equals($r->source_sha256,$n['source_sha256']))$matchingRevision=$r;
                    if(!hash_equals($r->source_sha256,$n['source_sha256'])||($event->status==='draft'&&$event->version!==$r->event_version))$reason='outdated';
                    elseif($event->status==='capacity_reserved'&&$event->version===$r->event_version+1&&$event->reservations()->exists()&&BookingEvidence::where('event_id',$event->id)->where('imported_revision_id',$r->id)->exists())$counts['capacity_confirmed']++;
                    else $reason='draft';}
                foreach($totals as $field=>&$total){if(($n[$field]??null)===null)$total['unknown_source_count']++;else$total['source_sum_cents']+=$n[$field];if($matchingRevision){$total['imported_sum_cents']+=$matchingRevision->normalized[$field];$total['matched_source_count']++;}}unset($total);
                if($reason){$counts[$reason]++;$gaps[]=['row_id'=>$row->id,'reason'=>$reason];}
            }
            $complete=$counts['unresolved']===0&&$counts['unmapped']===0&&$counts['outdated']===0&&$counts['source_rows']>0&&$counts['source_rows']>$counts['skipped'];
            return ['batch_id'=>$batchId,'version'=>$b->version,'counts'=>$counts,'gaps'=>$gaps,'accepted_source_differences'=>$acceptedDifferences,'domain_complete'=>$complete,'capacity_complete'=>$complete&&$counts['draft']===0,
                'reconciliation'=>array_map(fn($t)=>[...$t,'difference_cents'=>$t['unknown_source_count']?null:$t['source_sum_cents']-$t['imported_sum_cents']],$totals),
                'source_coverage_requires_owner_attestation'=>true,'pilot_approved'=>false,'public_availability_activated'=>false];
        });
    }
}
