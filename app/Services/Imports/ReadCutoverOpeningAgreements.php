<?php
namespace App\Services\Imports;

use App\Models\{CutoverOpeningSet, User};
use App\Services\Finance\Sandbox;

final class ReadCutoverOpeningAgreements
{
    public function execute(User $actor, int $setId): array
    {
        CutoverAccess::actor($actor); Sandbox::require('sandbox_cutover_bridge');
        return app(CutoverCohort::class)->run($actor, function ($a, $links, $events, $grants) use ($setId) {
            Sandbox::require('sandbox_cutover_bridge');
            $set = CutoverOpeningSet::whereKey($setId)->sharedLock()->firstOrFail();
            $saved = OpeningSetIntegrity::load($set);
            $s = app(ReadHoneyBookCutoverReadiness::class)->snapshot($links, $events, $grants);
            $missing = $s['missing'];
            if ($set->cohort_sha256 !== $s['view']['cohort_sha256']) $missing[] = 'opening_source_changed';
            if (!$set->expires_at->isFuture()) $missing[] = 'opening_expired';
            if ($missing === []) {
                $currentFacts = OpeningAgreementFacts::cohort($links, $events, $grants);
                if ($saved['manifest']['members'] !== OpeningAgreementFacts::hashes($currentFacts)) $missing[] = 'opening_capacity_evidence_changed';
            }
            $fields = ['event_id', 'event_version', 'customer_id', 'origin', 'authority', 'effective_at', 'currency', 'original_total_cents',
                'source_gross_received_cents', 'source_refund_cents', 'source_net_received_cents', 'raw_balance_cents', 'balance_cents', 'credit_cents',
                'source_balance_due_date', 'source_due_precision', 'crm_balance_due_at', 'deposit_target_cents', 'capacity_already_reserved', 'funds_verified'];
            $items = [];
            foreach ($saved['rows'] as $row) {
                $f = $saved['facts'][(string)$row->event_id];
                $items[] = ['opening_id' => $row->id, ...array_intersect_key($f, array_flip($fields)),
                    'accounting' => ['new_sales_cents' => 0, 'crm_cash_received_cents' => 0, 'historical_source_net_cents' => $f['source_net_received_cents']]];
            }
            return [...OpeningSetIntegrity::metadata($set, $saved['manifest']), 'current' => $missing === [],
                'missing' => array_values(array_unique($missing)), 'items' => $items];
        });
    }
}
