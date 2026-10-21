<?php
namespace App\Services\History;

use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoryImportBatch, HistoryImportRow, User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Imports\{ImportInput, ImportPersistence};
use Illuminate\Support\Str;

final class ImportSafeHistoricalRows
{
    public function execute(User $actor, int $batchId, #[\SensitiveParameter] array $input, string $key): array
    {
        HistoryAccess::actor($actor);
        $d = ImportInput::input($input, ['expected_batch_version' => 'required|integer|min:1',
            'expected_coverage_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'expected_plan_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'new_customer_locale' => 'required|in:es,en']);
        $d['expected_batch_version'] = (int)$d['expected_batch_version'];
        $hash = MutationKey::hash($d); $scope = 'owner:'.$actor->id.':history:'.$batchId.':bulk';
        return ImportPersistence::transaction(function () use ($actor,$batchId,$d,$hash,$scope,$key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); ImportInput::lock(); HistoryAccess::actor($actor);
            $batch = HistoryImportBatch::whereKey($batchId)->lockForUpdate()->firstOrFail(); $m = HistoryEnvelope::batch($batch);
            if (MutationKey::find($scope,$key,$hash,true)) return $m['bulk_receipts'][$scope.':'.$key]['result'];
            RecordWriteRejected::unless($batch->state === 'reviewing' && $batch->version === $d['expected_batch_version']
                && hash_equals($batch->coverage_sha256,$d['expected_coverage_sha256']),409,'El lote cambió. Revisa el resumen antes de importar.');
            $plan = HistoricalPlan::build($batch,$m);
            RecordWriteRejected::unless(hash_equals($plan['summary']['plan_sha256'],$d['expected_plan_sha256']),409,'Las coincidencias cambiaron. Revisa el resumen antes de importar.');
            $counts = ['imported'=>0,'unchanged'=>0,'review_required'=>$plan['summary']['review_required']];
            foreach ($plan['items'] as $item) {
                if ($item['state'] !== 'eligible') continue;
                // Earlier safe rows can establish the same source contact. Re-evaluate under the
                // still-held singleton; the preflight already rejected contradicting identities.
                $current = app(ReadHistoricalRow::class)->execute($actor,$batchId,$item['row_id']);
                $match = $current['customer_match']; $customer = $item['customer'];
                if (count($match['candidates']) === 1 && in_array($match['state'],['linked','suggested'],true)) {
                    $c=$match['candidates'][0]; $customer=['mode'=>'link','id'=>$c['id'],'expected_version'=>$c['version']];
                }
                if ($customer['mode'] === 'create') $customer['locale']=$d['new_customer_locale'];
                $result = app(CommitHistoricalRow::class)->execute($actor,$batchId,$item['row_id'],[
                    'expected_batch_version'=>HistoryImportBatch::findOrFail($batchId)->version,
                    'expected_row_version'=>$item['version'],'expected_preview_sha256'=>$item['preview_sha256'],
                    'expected_match_sha256'=>$match['expected_match_sha256'],'customer'=>$customer,
                    'source_status_confirmation'=>$item['status'],'event_type_confirmation'=>$item['event_type'],
                ], (string)Str::uuid());
                $counts[$result['state']]++;
            }
            $batch=HistoryImportBatch::whereKey($batchId)->lockForUpdate()->firstOrFail(); $m=HistoryEnvelope::batch($batch);
            $batch->version++; $m['version']=$batch->version;
            $result=['batch_id'=>$batchId,'batch_version'=>$batch->version,...$counts];
            $m['bulk_receipts'][$scope.':'.$key]=['request_sha256'=>$hash,'result'=>$result];
            $batch->manifest=$m; $batch->coverage_sha256=MutationKey::hash($m); $batch->save();
            Audit::recordFor($actor,'history.bulk_committed',$batch,[], $counts);
            MutationKey::store($scope,$key,$hash,'history_import_batch',$batchId);
            return $result;
        });
    }
}
