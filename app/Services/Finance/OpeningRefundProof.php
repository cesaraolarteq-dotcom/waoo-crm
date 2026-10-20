<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{LedgerEntry, OpeningReceiptAllocation, OpeningRefundEvidence, OpeningRefundObservation, WebhookReceipt};
use App\Services\Integrity\EvidenceV1;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Crypt, DB};

/** Read captured facts only. A proof is not permission to refund, classify or collect. */
final class OpeningRefundProof
{
    public static function inspect(LedgerEntry $entry, array $context, array &$parents): array
    {
        $failed = ['valid' => false, 'refund_sha256' => null, 'parent_ledger_entry_id' => null,
            'issue_codes' => ['refund_evidence_required']];
        $row = OpeningRefundEvidence::where('ledger_entry_id', $entry->id)->sharedLock()->first();
        if (!$row) return $failed;
        $failed['issue_codes'] = ['refund_evidence_invalid'];
        try {
            $p = $row->payload;
            if (!is_array($p) || ($p['schema_version'] ?? null) !== 1 || !hash_equals($row->payload_sha256, MutationKey::hash($p))) return $failed;
            $parent = LedgerEntry::whereKey($row->parent_ledger_entry_id)->sharedLock()->first();
            $o = OpeningRefundObservation::whereKey($row->opening_refund_observation_id)->sharedLock()->first();
            if (!$parent || !$o) return $failed;
            $observed = self::observation($o, $parent); $provider = $p['provider'];
            $callback = $observed['object'];
            if ($observed['payload']['provider'] !== $provider || RefundProviderFacts::read($provider) !== $provider
                || !RefundProviderFacts::valid($provider, $callback) || $provider['refund']['status'] !== 'succeeded'
                || !in_array($observed['payload']['reason'], ['refund_allocation_pending', 'refund_total_exceeds_parent'], true)
                || !in_array($observed['type'], ['refund.created', 'refund.updated', 'charge.refund.updated'], true)
                || ($callback['object'] ?? null) !== 'refund'
                || $p['ledger_entry_id'] !== $entry->id || $p['parent_ledger_entry_id'] !== $parent->id
                || $p['observation_id'] !== $o->id || $p['observation_sha256'] !== $o->payload_sha256
                || $p['ledger_sha256'] !== MutationKey::hash(EvidenceV1::model($entry, 'ledger'))
                || $p['parent_ledger_sha256'] !== MutationKey::hash(EvidenceV1::model($parent, 'ledger'))
                || $entry->source_id !== $o->webhook_receipt_id || $entry->source_type !== 'verified_stripe_refund'
                || $entry->kind !== 'refund' || $entry->method !== 'stripe' || $entry->environment !== 'test' || $entry->currency !== 'USD'
                || $entry->verified_by !== null || $entry->event_id !== $parent->event_id || $entry->financial_revision_id !== $parent->financial_revision_id
                || $entry->amount_cents !== $provider['refund']['amount'] || $entry->external_reference !== $provider['refund']['id']
                || $entry->external_identity_key !== 'stripe-refund:test:'.$provider['account'].':'.$provider['refund']['id']
                || $entry->received_at?->timestamp !== $provider['refund']['created']
                || $parent->kind !== 'receipt' || $parent->method !== 'stripe' || $parent->source_type !== 'verified_stripe_webhook'
                || $parent->environment !== 'test' || $parent->currency !== 'USD' || $parent->verified_by !== null
                || $parent->amount_cents !== $provider['charge']['amount'] || $parent->external_reference !== $provider['payment']['id']
                || $parent->external_identity_key !== 'stripe:test:'.$provider['account'].':'.$provider['payment']['id']
                || $parent->received_at?->timestamp !== $provider['charge']['created']) return $failed;
            $issues = [];
            if ($entry->event_id !== $context['binding']->event_id || $entry->financial_revision_id !== $context['finance']->id
                || $entry->received_at->lessThan($context['transfer']->effective_at)) $issues[] = 'refund_agreement_mismatch';
            $parentProof = OpeningReceiptProof::inspect($parent, $context);
            if (!$parentProof['eligible'] || $p['parent_stripe_evidence_sha256'] !== $parentProof['stripe_evidence_sha256']) $issues[] = 'refund_parent_evidence_invalid';
            $allocation = OpeningReceiptAllocation::where('ledger_entry_id', $parent->id)->sharedLock()->first();
            if (!$allocation) $issues[] = 'refund_parent_allocation_required';
            else {
                try { OpeningReceiptLink::verify($allocation, $parent, $context, $parentProof); }
                catch (RecordWriteRejected) { $issues[] = 'refund_parent_allocation_invalid'; }
            }
            if (!array_key_exists($parent->id, $parents)) $parents[$parent->id] = self::parentObservations($parent);
            $state = $parents[$parent->id]; $issues = [...$issues, ...$state['issues']];
            foreach ($state['observations'] as $later) {
                if ($later['id'] < $o->id) continue;
                $facts = $later['facts'];
                if (($facts['provider']['refund']['id'] ?? null) === $provider['refund']['id']
                    && (!in_array($facts['reason'], ['refund_allocation_pending', 'refund_duplicate'], true) || $facts['provider'] !== $provider))
                    $issues[] = 'refund_current_observation_conflict';
            }
            return ['valid' => true, 'refund_sha256' => MutationKey::hash(['schema_version' => 1,
                'ledger_sha256' => $p['ledger_sha256'], 'refund_evidence_sha256' => $row->payload_sha256]),
                'parent_ledger_entry_id' => $parent->id, 'issue_codes' => array_values(array_unique($issues))];
        } catch (\Illuminate\Database\QueryException $e) { throw $e; }
        catch (\Throwable) { return $failed; }
    }

    private static function observation(OpeningRefundObservation $row, LedgerEntry $parent): array
    {
        $p = $row->payload;
        if (!is_array($p) || ($p['schema_version'] ?? null) !== 1 || !hash_equals($row->payload_sha256, MutationKey::hash($p))
            || $row->parent_ledger_entry_id !== $parent->id || $p['parent_ledger_entry_id'] !== $parent->id
            || $p['parent_ledger_sha256'] !== MutationKey::hash(EvidenceV1::model($parent, 'ledger')))
            throw new \UnexpectedValueException('Invalid private refund observation.');
        $w = WebhookReceipt::whereKey($row->webhook_receipt_id)->sharedLock()->firstOrFail();
        $payload = json_decode(Crypt::decryptString($w->encrypted_payload), true, flags: JSON_THROW_ON_ERROR);
        $webhook = ['id' => $w->getRawOriginal('id'), 'event_type' => $w->getRawOriginal('event_type'),
            'raw_sha256' => $w->getRawOriginal('raw_sha256'), 'stripe_account_id' => $w->getRawOriginal('stripe_account_id'),
            'verified_at' => CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $w->getRawOriginal('verified_at'), 'UTC')->toIso8601String(),
            'sanitized_payload_sha256' => MutationKey::hash($payload)];
        if ($p['webhook'] !== $webhook || $w->state !== 'processed' || !$w->processed_at || !$w->verified_at || $w->verified_at->isFuture()
            || !preg_match('/^[a-f0-9]{64}$/D', $w->raw_sha256) || $payload['id'] !== $w->id || $payload['type'] !== $w->event_type
            || $payload['livemode'] !== false || $payload['account'] !== $w->stripe_account_id
            || (!in_array($w->event_type, ['refund.created', 'refund.updated', 'charge.refund.updated', 'charge.refunded'], true)
                && !str_starts_with($w->event_type, 'charge.dispute.'))
            || ($w->stripe_account_id !== null && $w->stripe_account_id !== config('waoo.stripe_test_account')))
            throw new \UnexpectedValueException('Invalid private refund callback.');
        return ['payload' => $p, 'object' => $payload['data']['object'], 'type' => $w->event_type];
    }

    private static function parentObservations(LedgerEntry $parent): array
    {
        $rows = OpeningRefundObservation::where('parent_ledger_entry_id', $parent->id)->orderBy('id')->limit(1001)->sharedLock()->get();
        if ($rows->count() > 1000) return ['issues' => ['refund_observation_volume_exceeded'], 'observations' => []];
        $issues = []; $observations = [];
        foreach ($rows as $row) {
            try {
                $o = self::observation($row, $parent); $facts = $o['payload'];
                $observations[] = ['id' => $row->id, 'facts' => $facts];
                if ($o['type'] === 'charge.refunded' || str_starts_with($o['type'], 'charge.dispute.')
                    || in_array($facts['reason'], ['refund_provider_binding_invalid', 'refund_identity_conflict', 'refund_total_exceeds_parent'], true))
                    $issues[] = 'refund_parent_provider_review_required';
            } catch (\Illuminate\Database\QueryException $e) { throw $e; }
            catch (\Throwable) { $issues[] = 'refund_observation_integrity_invalid'; }
        }
        $gross = DB::table('opening_refund_evidence')->join('ledger_entries', 'ledger_entries.id', '=', 'opening_refund_evidence.ledger_entry_id')
            ->where('opening_refund_evidence.parent_ledger_entry_id', $parent->id)->sum('ledger_entries.amount_cents');
        if ($gross > $parent->amount_cents) $issues[] = 'refund_parent_gross_exceeded';
        if (DB::table('payment_exceptions')->where('event_id', $parent->event_id)->where('state', 'open')->where('kind', 'opening_provider_review')->sharedLock()->first())
            $issues[] = 'refund_parent_provider_review_required';
        return ['issues' => array_values(array_unique($issues)), 'observations' => $observations];
    }
}
