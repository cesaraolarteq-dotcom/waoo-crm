<?php
namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{CutoverReconciliation, User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;

final class PrepareHoneyBookCutoverReconciliation
{
    public function execute(User $actor, array $input, string $key): CutoverReconciliation
    {
        $actor = CutoverAccess::actor($actor);
        $d = ImportInput::input($input, ['expected_cohort_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'source_scope_reviewed' => 'required|accepted']);
        $d['source_scope_reviewed'] = true;
        $hash = MutationKey::hash($d); $scope = "owner:{$actor->id}:cutover:prepare";
        return app(CutoverCohort::class)->run($actor, function ($actor, $links, $events, $grants) use ($d, $key, $hash, $scope) {
            if ($receipt = MutationKey::find($scope, $key, $hash, true)) {
                $saved = CutoverReconciliation::whereKey($receipt->result_id)->sharedLock()->firstOrFail();
                RecordWriteRejected::unless(CutoverIntegrity::reconciliation($saved), 409, 'La evidencia preparada requiere revisar su integridad.');
                return $saved;
            }
            $s = app(ReadHoneyBookCutoverReadiness::class)->snapshot($links, $events, $grants);
            RecordWriteRejected::unless(hash_equals($s['view']['cohort_sha256'], $d['expected_cohort_sha256']) && $s['missing'] === [], 409, 'La evidencia está incompleta, vencida o cambió. Vuelve a revisar el grupo.');
            $snapshot = CutoverReconciliation::where('cohort_sha256', $d['expected_cohort_sha256'])->sharedLock()->first();
            if ($snapshot) {
                $valid = CutoverIntegrity::reconciliation($snapshot);
                RecordWriteRejected::unless($valid, 409, 'La evidencia preparada requiere revisar su integridad.');
            } else {
                $snapshot = CutoverReconciliation::create(['cohort_sha256' => $d['expected_cohort_sha256'], 'payload' => $s['payload'],
                    'payload_sha256' => MutationKey::hash($s['payload']), 'source_observed_at' => $s['oldest'], 'expires_at' => $s['oldest']->addMinutes(CutoverAccess::maxAge()),
                    'policy_max_age_minutes' => CutoverAccess::maxAge(), 'created_by' => $actor->id]);
                Audit::recordFor($actor, 'cutover.evidence_prepared', $snapshot, [], ['cohort_sha256' => $snapshot->cohort_sha256, 'event_count' => $links->count(), 'payload_sha256' => $snapshot->payload_sha256]);
            }
            MutationKey::store($scope, $key, $hash, 'cutover_reconciliation', $snapshot->id);
            return $snapshot;
        });
    }
}
