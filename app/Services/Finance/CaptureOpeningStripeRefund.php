<?php
namespace App\Services\Finance;

use App\Models\{Event, ImportedProjectLink, LedgerEntry, OpeningRefundEvidence, OpeningRefundObservation, OpeningStripeEvidence, WebhookReceipt};
use App\Services\{Audit, Integrity\EvidenceV1};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Crypt, DB};

/** Retain authenticated source refund facts. Never request a refund or classify it against principal. */
final class CaptureOpeningStripeRefund
{
    public function execute(string $webhookReceiptId): void
    {
        Sandbox::require(); $receipt = WebhookReceipt::findOrFail($webhookReceiptId);
        if (in_array($receipt->state, ['processed','ignored','reconciliation_required'], true)) return;
        $payload = json_decode(Crypt::decryptString($receipt->encrypted_payload), true, flags: JSON_THROW_ON_ERROR);
        $object = $payload['data']['object'] ?? [];
        if (!in_array($receipt->event_type, ['refund.created','refund.updated','charge.refund.updated'], true) || ($object['object'] ?? null) !== 'refund'
            || !is_string($object['id'] ?? null) || !preg_match('/^re_[A-Za-z0-9_]{1,200}$/D', $object['id']))
            throw new \RuntimeException('Private refund callback cannot be verified.');
        try { $provider = RefundProviderFacts::read(app(StripeRefundGateway::class)->retrieveRefund($object['id'])); }
        catch (\Throwable) { throw new \RuntimeException('Private Stripe refund verification unavailable.'); }
        $chargeValid = RefundProviderFacts::validCharge($provider, $provider['charge']['id'] ?? '')
            && (!$receipt->stripe_account_id || $receipt->stripe_account_id === $provider['account'])
            && (($object['charge'] ?? null) === null || $object['charge'] === $provider['charge']['id'])
            && (($object['payment_intent'] ?? null) === null || $object['payment_intent'] === $provider['payment']['id']);
        $valid = $chargeValid && RefundProviderFacts::valid($provider, $object);
        $identity = $chargeValid ? 'stripe:test:'.$provider['account'].':'.$provider['payment']['id'] : null;
        $hint = $identity ? LedgerEntry::where('external_identity_key', $identity)->first() : null;
        // Native/TIP/ambiguous refunds get an observation only. No source event or parent is invented.
        if ($hint && (!ImportedProjectLink::where('event_id', $hint->event_id)->exists() || !self::parentValid($hint, $provider))) $hint = null;
        DB::transaction(function () use ($receipt, $provider, $valid, $identity, $hint, $object) {
            $parent = null;
            if ($hint) {
                Event::whereKey($hint->event_id)->lockForUpdate()->firstOrFail();
                $parent = LedgerEntry::whereKey($hint->id)->lockForUpdate()->first();
                if (!$parent || $parent->external_identity_key !== $identity || !self::parentValid($parent, $provider)
                    || !ImportedProjectLink::where('event_id', $parent->event_id)->sharedLock()->first()) $parent = null;
            }
            $locked = WebhookReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->state, ['processed','ignored','reconciliation_required'], true)) return;
            // Provider reads ran outside locks. Bind them to the exact authenticated callback inspected.
            foreach (['raw_sha256','encrypted_payload','event_type','stripe_account_id','verified_at'] as $field)
                if ($locked->getRawOriginal($field) !== $receipt->getRawOriginal($field))
                    throw new \RuntimeException('Private refund callback changed during verification.');
            if (!$locked->verified_at || $locked->verified_at->isFuture())
                throw new \RuntimeException('Private refund callback cannot be verified.');
            $refund = $provider['refund']; $newEntry = null; $existing = null;
            $reason = !$valid ? 'refund_provider_binding_invalid' : (!$parent ? 'refund_source_parent_required' : 'refund_'.$refund['status']);
            $refundIdentity = $identity ? 'stripe-refund:test:'.$provider['account'].':'.$object['id'] : null;
            if ($parent) $existing = LedgerEntry::where('external_identity_key', $refundIdentity)->lockForUpdate()->first();
            if ($valid && $parent && $refund['status'] === 'succeeded') {
                if ($existing) {
                    $evidence = OpeningRefundEvidence::where('ledger_entry_id', $existing->id)->sharedLock()->first();
                    $reason = self::sameRefund($existing, $parent, $provider, $evidence) ? 'refund_duplicate' : 'refund_identity_conflict';
                } else {
                    $newEntry = LedgerEntry::create(['event_id' => $parent->event_id, 'financial_revision_id' => $parent->financial_revision_id,
                        'environment' => 'test', 'kind' => 'refund', 'method' => 'stripe', 'amount_cents' => $refund['amount'], 'currency' => 'USD',
                        'received_at' => CarbonImmutable::createFromTimestampUTC($refund['created']), 'external_reference' => $refund['id'],
                        'external_identity_key' => $refundIdentity, 'verified_by' => null, 'source_type' => 'verified_stripe_refund', 'source_id' => $locked->id]);
                    $previousGross = (int) DB::table('opening_refund_evidence')->join('ledger_entries', 'ledger_entries.id', '=', 'opening_refund_evidence.ledger_entry_id')
                        ->where('opening_refund_evidence.parent_ledger_entry_id', $parent->id)->sum('ledger_entries.amount_cents');
                    $reason = $previousGross + $newEntry->amount_cents > $parent->amount_cents ? 'refund_total_exceeds_parent' : 'refund_allocation_pending';
                }
            }
            $facts = ['schema_version' => 1, 'reason' => $reason, 'provider' => $provider,
                'parent_ledger_entry_id' => $parent?->id, 'parent_ledger_sha256' => $parent ? MutationKey::hash(EvidenceV1::model($parent, 'ledger')) : null,
                'webhook' => self::webhook($locked)];
            $observation = OpeningRefundObservation::create(['webhook_receipt_id' => $locked->id,
                'parent_ledger_entry_id' => $parent?->id, 'payload' => $facts, 'payload_sha256' => MutationKey::hash($facts)]);
            if ($newEntry) {
                $body = ['schema_version' => 1, 'ledger_entry_id' => $newEntry->id,
                    'ledger_sha256' => MutationKey::hash(EvidenceV1::model($newEntry->fresh(), 'ledger')),
                    'parent_ledger_entry_id' => $parent->id, 'parent_ledger_sha256' => $facts['parent_ledger_sha256'],
                    'parent_stripe_evidence_sha256' => OpeningStripeEvidence::where('ledger_entry_id', $parent->id)->value('payload_sha256'),
                    'observation_id' => $observation->id, 'observation_sha256' => $observation->payload_sha256, 'provider' => $provider];
                OpeningRefundEvidence::create(['ledger_entry_id' => $newEntry->id, 'parent_ledger_entry_id' => $parent->id,
                    'opening_refund_observation_id' => $observation->id, 'payload' => $body, 'payload_sha256' => MutationKey::hash($body)]);
                Audit::recordSystem('payment.stripe_refund_received', $newEntry, [], ['parent_ledger_entry_id' => $parent->id, 'observation_id' => $observation->id, 'amount_cents' => $newEntry->amount_cents]);
            }
            Audit::recordSystem('opening.refund_observed', $observation, [], ['parent_ledger_entry_id' => $parent?->id, 'reason' => $reason, 'payload_sha256' => $observation->payload_sha256]);
            if ($parent) {
                $reviewKind = !$valid || ($existing && $reason !== 'refund_duplicate') ? 'opening_provider_review' : 'opening_refund_review';
                DB::table('payment_exceptions')->insertOrIgnore(['event_id' => $parent->event_id, 'financial_revision_id' => $parent->financial_revision_id,
                    'kind' => $reviewKind, 'exception_key' => hash('sha256', $parent->event_id.':'.$refundIdentity.':'.$reviewKind), 'state' => 'open',
                    'context' => json_encode(['parent_ledger_entry_id' => $parent->id, 'observation_id' => $observation->id, 'reason' => $reason], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now()]);
            }
            $locked->fill(['state' => 'processed', 'processed_at' => now(), 'last_error' => null, 'attempts' => $locked->attempts + 1])->save();
        }, 3);
    }
    private static function parentValid(LedgerEntry $parent, array $p): bool
    {
        return $parent->kind === 'receipt' && $parent->method === 'stripe' && $parent->source_type === 'verified_stripe_webhook'
            && $parent->environment === 'test' && $parent->currency === 'USD' && $parent->verified_by === null
            && $parent->amount_cents === $p['charge']['amount'] && $parent->external_reference === $p['payment']['id']
            && $parent->received_at && $parent->received_at->timestamp === $p['charge']['created'];
    }
    private static function sameRefund(LedgerEntry $entry, LedgerEntry $parent, array $p, ?OpeningRefundEvidence $row): bool
    {
        try {
            if (!$row) return false; $body = $row->payload;
            return $entry->event_id === $parent->event_id && $entry->financial_revision_id === $parent->financial_revision_id
                && $entry->kind === 'refund' && $entry->method === 'stripe' && $entry->environment === 'test' && $entry->currency === 'USD'
                && $entry->amount_cents === $p['refund']['amount'] && $entry->external_reference === $p['refund']['id']
                && $entry->received_at?->timestamp === $p['refund']['created'] && $row->parent_ledger_entry_id === $parent->id
                && ($body['schema_version'] ?? null) === 1 && $body['provider'] === $p
                && $body['ledger_sha256'] === MutationKey::hash(EvidenceV1::model($entry, 'ledger'))
                && hash_equals($row->payload_sha256, MutationKey::hash($body));
        } catch (\Illuminate\Database\QueryException $e) { throw $e; }
        catch (\Throwable) { return false; }
    }
    private static function webhook(WebhookReceipt $receipt): array
    {
        return ['id' => $receipt->getRawOriginal('id'), 'event_type' => $receipt->getRawOriginal('event_type'),
            'raw_sha256' => $receipt->getRawOriginal('raw_sha256'), 'stripe_account_id' => $receipt->getRawOriginal('stripe_account_id'),
            'verified_at' => CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $receipt->getRawOriginal('verified_at'), 'UTC')->toIso8601String(),
            'sanitized_payload_sha256' => MutationKey::hash(json_decode(Crypt::decryptString($receipt->getRawOriginal('encrypted_payload')), true, flags: JSON_THROW_ON_ERROR))];
    }
}
