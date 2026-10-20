<?php
namespace App\Services\Finance;

use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class OpeningReceiptProof
{
    public static function inspect(LedgerEntry $entry, array $context): array
    {
        $issues = []; $audit = null;
        if ($entry->event_id !== $context['binding']->event_id || $entry->financial_revision_id !== $context['finance']->id) $issues[] = 'receipt_agreement_mismatch';
        if ($entry->environment !== 'test' || $entry->currency !== 'USD') $issues[] = 'receipt_environment_or_currency';
        if ($entry->kind !== 'receipt') $issues[] = 'refund_allocation_pending';
        if ($entry->amount_cents <= 0 || $entry->amount_cents > 100000000) $issues[] = 'receipt_amount_outside_scope';
        if (!$entry->received_at || $entry->received_at->lessThan($context['transfer']->effective_at) || $entry->received_at->isFuture()) $issues[] = 'receipt_time_outside_transfer';
        if ($entry->method === 'stripe') $issues[] = 'stripe_allocation_pending';
        if ($entry->method !== 'zelle' || $entry->source_type !== 'owner_verified' || !$entry->verified_by) $issues[] = 'owner_verification_required';
        if ($entry->external_identity_key !== 'zelle:test:'.hash('sha256', mb_strtolower($entry->external_reference ?? ''))) $issues[] = 'receipt_identity_invalid';
        $audits = DB::table('audit_entries')->forceIndex('opening_receipt_audit_index')->where('subject_type', 'ledger_entries')->where('subject_id', (string)$entry->id)
            ->where('action', 'payment.zelle_received')->orderBy('id')->limit(2)->sharedLock()->get();
        if ($audits->count() !== 1) $issues[] = 'receipt_audit_required';
        else {
            $audit = $audits->first();
            try {
                $after = json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR);
                $valid = $audit->actor_id === $entry->verified_by;
                foreach (['event_id', 'financial_revision_id', 'kind', 'method', 'amount_cents', 'currency', 'environment', 'verified_by', 'source_type', 'external_reference', 'external_identity_key'] as $f) {
                    $valid = $valid && array_key_exists($f, $after) && $after[$f] === $entry->getAttribute($f);
                }
                $valid = $valid && isset($after['received_at']) && CarbonImmutable::parse($after['received_at'], 'UTC')->equalTo($entry->received_at);
                if (!$valid) $issues[] = 'receipt_audit_mismatch';
            } catch (\Throwable) { $issues[] = 'receipt_audit_mismatch'; }
        }
        return ['eligible' => $issues === [], 'issue_codes' => array_values(array_unique($issues)),
            'ledger_sha256' => MutationKey::hash(\App\Services\Integrity\EvidenceV1::model($entry, 'ledger')),
            'receipt_sha256' => MutationKey::hash(['ledger' => \App\Services\Integrity\EvidenceV1::model($entry, 'ledger'), 'audit' => $audit ? \App\Services\Integrity\EvidenceV1::audit($audit) : null])];
    }
}
