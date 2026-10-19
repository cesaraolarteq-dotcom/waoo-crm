<?php
namespace App\Services\Imports;

use App\Models\{CutoverReconciliation, User};
use App\Services\Finance\MutationKey;
use Carbon\CarbonImmutable;

final class ReadHoneyBookCutoverReadiness
{
    public function execute(User $actor): array
    {
        return app(CutoverCohort::class)->run($actor, fn ($a, $links, $events, $grants) => $this->snapshot($links, $events, $grants)['view']);
    }

    /** Called inside the same cohort protocol by preparation; never accept members from a browser. */
    public function snapshot($links, $events, $grants): array
    {
        $members = []; $missing = []; $unknowns = 0; $reviewed = 0; $documents = 0; $oldest = null;
        $totals = array_fill_keys(['total_cents', 'gross_received_cents', 'refund_cents', 'net_paid_cents', 'balance_cents', 'credit_cents'], 0);
        foreach ($links as $link) {
            $m = app(CutoverMember::class)->inspect($link, $events->get($link->event_id), $grants);
            $v = $m['view']; $missing = [...$missing, ...$v['missing']];
            $reviewed += (int)($v['review']['current'] ?? false);
            $documents += (int)($m['document'] !== null && !in_array('document_integrity_invalid', $v['missing'], true));
            if ($v['source']['total_cents'] === null) $unknowns++;
            foreach ($totals as $field => &$sum) {
                $value = $field === 'credit_cents' ? ($v['source']['balance_cents'] === null ? null : max(0, -$v['source']['balance_cents'])) : $v['source'][$field];
                if ($field === 'balance_cents' && $value !== null) $value = max(0, $value);
                $sum = $sum === null || $value === null ? null : $sum + $value;
            } unset($sum);
            if ($m['review'] && ($oldest === null || $m['review']->source_observed_at->lessThan($oldest))) $oldest = $m['review']->source_observed_at;
            $members[] = ['binding' => $m['binding'], 'review_id' => $m['review']?->id, 'review_version' => $m['review']?->version,
                'review_payload_sha256' => $m['review']?->payload_sha256, 'source_observed_at' => $m['review']?->source_observed_at->toIso8601String(),
                'document_id' => $m['document']?->id, 'document_sha256' => $m['document']?->sha256];
        }
        $scope = app(CutoverSourceScope::class)->inspect($links);
        $missing = [...$missing, ...$scope['missing']];
        if ($links->isEmpty()) $missing[] = 'cohort_empty';
        if (CutoverAccess::maxAge() === null) $missing[] = 'freshness_policy_required';
        $missing = array_values(array_unique($missing));
        $payload = ['schema_version' => 1, 'policy_max_age_minutes' => CutoverAccess::maxAge(), 'members' => $members, 'totals' => $totals,
            'source_scope' => $scope['payload'], 'source_scope_sha256' => $scope['hash'], 'source_scope_reviewed' => true];
        $cohortHash = MutationKey::hash($payload);
        $last = CutoverReconciliation::orderByDesc('id')->sharedLock()->first();
        $current = false;
        if ($last) {
            $integrity = CutoverIntegrity::reconciliation($last);
            $current = $missing === [] && $integrity && hash_equals($last->cohort_sha256, $cohortHash) && $last->expires_at->isFuture();
        }
        $view = ['cohort_sha256' => $cohortHash, 'counts' => ['events' => $links->count(), 'reviewed' => $reviewed, 'documents' => $documents, 'pending_source_rows' => $scope['pending_rows']],
            'totals' => $totals, 'unknowns' => $unknowns, 'readiness' => ['ready' => false, 'evidence_ready' => $missing === [],
                'missing' => [...$missing, ...($last && !$integrity ? ['reconciliation_integrity_invalid'] : []), 'financial_bridge_not_implemented', 'transfer_not_implemented', ...(!$current ? ['reconciliation_required'] : [])]],
            'reconciliation' => $last ? ['id' => $last->id, 'cohort_sha256' => $last->cohort_sha256, 'source_observed_at' => $last->source_observed_at->toIso8601String(), 'expires_at' => $last->expires_at->toIso8601String(), 'current' => $current] : null,
            'sending_enabled' => false, 'transfer_available' => false];
        return compact('view', 'payload', 'oldest', 'missing', 'members');
    }
}
