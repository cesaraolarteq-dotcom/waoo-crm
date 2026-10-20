<?php
namespace App\Services\Imports;

use App\Models\{CutoverReconciliation, HoneyBookCutoverReview};
use App\Services\Finance\MutationKey;
use Carbon\CarbonImmutable;

final class CutoverIntegrity
{
    public static function review(HoneyBookCutoverReview $review): ?array
    {
        try {
            $p = $review->payload;
            if (!is_array($p) || !hash_equals($review->payload_sha256, MutationKey::hash($p))
                || !hash_equals($review->binding_sha256, MutationKey::hash($p['binding']))
                || ($p['source_observed_at'] ?? null) !== $review->source_observed_at->toIso8601String()
                || ($p['review_version'] ?? null) !== $review->version || ($p['reviewed_by'] ?? null) !== $review->created_by
                || ($p['document_id'] ?? null) !== $review->cutover_original_document_id
                || ($p['owner_attests_signed_original'] ?? null) !== true || ($p['owner_money_reviewed'] ?? null) !== true) return null;
            return $p;
        } catch (\Throwable) { return null; }
    }
    public static function reconciliation(CutoverReconciliation $snapshot): bool
    {
        try {
            $p = $snapshot->payload;
            if (!is_array($p) || !hash_equals($snapshot->payload_sha256, MutationKey::hash($p))
                || !hash_equals($snapshot->cohort_sha256, MutationKey::hash($p))
                || $snapshot->policy_max_age_minutes !== 240 || ($p['policy_max_age_minutes'] ?? null) !== 240 || empty($p['members'])) return false;
            $oldest = collect($p['members'])->map(fn ($m) => CarbonImmutable::parse($m['source_observed_at']))->sortBy(fn ($d) => $d->getTimestamp())->first();
            return $oldest->equalTo($snapshot->source_observed_at) && $oldest->addMinutes(240)->equalTo($snapshot->expires_at);
        } catch (\Throwable) { return false; }
    }
}
