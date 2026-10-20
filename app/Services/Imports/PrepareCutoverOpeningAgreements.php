<?php
namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{CutoverOpeningAgreement, CutoverOpeningSet, CutoverReconciliation, User};
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};

final class PrepareCutoverOpeningAgreements
{
    public function execute(User $actor, array $input, string $key): array
    {
        CutoverAccess::actor($actor); Sandbox::require('sandbox_cutover_bridge');
        $d = ImportInput::input($input, ['reconciliation_id' => 'required|integer|min:1',
            'expected_cohort_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D']);
        $d['reconciliation_id'] = (int)$d['reconciliation_id'];
        $hash = MutationKey::hash($d); $scope = 'owner:'.$actor->id.':cutover:openings';
        return app(CutoverCohort::class)->run($actor, function ($actor, $links, $events, $grants) use ($d, $hash, $key, $scope) {
            Sandbox::require('sandbox_cutover_bridge');
            if ($receipt = MutationKey::find($scope, $key, $hash, true)) {
                $set = CutoverOpeningSet::whereKey($receipt->result_id)->sharedLock()->firstOrFail();
                RecordWriteRejected::unless($set->cutover_reconciliation_id === $d['reconciliation_id']
                    && $set->cohort_sha256 === $d['expected_cohort_sha256'], 409, 'La solicitud de apertura requiere revisión.');
                $saved = OpeningSetIntegrity::load($set); return OpeningSetIntegrity::metadata($set, $saved['manifest']);
            }
            $reconciliation = CutoverReconciliation::whereKey($d['reconciliation_id'])->sharedLock()->firstOrFail();
            $s = app(ReadHoneyBookCutoverReadiness::class)->snapshot($links, $events, $grants);
            RecordWriteRejected::unless(CutoverIntegrity::reconciliation($reconciliation) && $reconciliation->expires_at->isFuture()
                && $s['missing'] === [] && hash_equals($reconciliation->cohort_sha256, $d['expected_cohort_sha256'])
                && hash_equals($reconciliation->cohort_sha256, $s['view']['cohort_sha256']), 409, 'La conciliación está vencida, incompleta o cambió. Vuelve a revisar el grupo.');
            $facts = OpeningAgreementFacts::cohort($links, $events, $grants); $members = OpeningAgreementFacts::hashes($facts);
            $set = CutoverOpeningSet::where('cutover_reconciliation_id', $reconciliation->id)->sharedLock()->first();
            if ($set) {
                $saved = OpeningSetIntegrity::load($set);
                RecordWriteRejected::unless($saved['manifest']['members'] === $members, 409, 'La evidencia de capacidad cambió. Revisa de nuevo antes de preparar la apertura.');
                $manifest = $saved['manifest'];
            } else {
                $manifest = ['schema_version' => 1, 'reconciliation_id' => $reconciliation->id, 'cohort_sha256' => $reconciliation->cohort_sha256,
                    'source_observed_at' => $reconciliation->source_observed_at->toIso8601String(), 'expires_at' => $reconciliation->expires_at->toIso8601String(),
                    'created_by' => $actor->id, 'authority' => 'preparation_only', 'effective_at' => null, 'members' => $members];
                $set = CutoverOpeningSet::create(['cutover_reconciliation_id' => $reconciliation->id, 'cohort_sha256' => $reconciliation->cohort_sha256,
                    'source_observed_at' => $reconciliation->source_observed_at, 'expires_at' => $reconciliation->expires_at,
                    'created_by' => $actor->id, 'payload' => $manifest, 'payload_sha256' => MutationKey::hash($manifest)]);
                foreach ($facts as $f) {
                    $payload = ['set_id' => $set->id, 'reconciliation_id' => $reconciliation->id, 'facts' => $f];
                    CutoverOpeningAgreement::create(['cutover_opening_set_id' => $set->id, 'event_id' => $f['event_id'],
                        'imported_project_link_id' => $f['imported_project_link_id'], 'imported_project_revision_id' => $f['source_revision_id'],
                        'payload' => $payload, 'payload_sha256' => MutationKey::hash($payload)]);
                }
                OpeningSetIntegrity::load($set);
                Audit::recordFor($actor, 'cutover.openings_prepared', $set, [], ['reconciliation_id' => $reconciliation->id,
                    'event_count' => count($facts), 'cohort_sha256' => $set->cohort_sha256, 'payload_sha256' => $set->payload_sha256]);
            }
            RecordWriteRejected::unless($set->expires_at->isFuture(), 409, 'La conciliación venció durante la preparación.');
            MutationKey::store($scope, $key, $hash, 'cutover_opening_set', $set->id);
            return OpeningSetIntegrity::metadata($set, $manifest);
        });
    }
}
