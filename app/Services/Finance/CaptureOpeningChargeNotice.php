<?php
namespace App\Services\Finance;

use App\Models\{Event, ImportedProjectLink, LedgerEntry, OpeningRefundObservation, WebhookReceipt};
use App\Services\{Audit, Integrity\EvidenceV1};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Crypt, DB};

/** Charge-level money uncertainty. No partial embedded refund list is treated as complete. */
final class CaptureOpeningChargeNotice
{
    public function execute(string $receiptId): void
    {
        Sandbox::require(); $receipt = WebhookReceipt::findOrFail($receiptId);
        if (in_array($receipt->state, ['processed','ignored','reconciliation_required'], true)) return;
        $payload = json_decode(Crypt::decryptString($receipt->encrypted_payload), true, flags: JSON_THROW_ON_ERROR);
        $object = $payload['data']['object']; $dispute = str_starts_with($receipt->event_type, 'charge.dispute.');
        if (!$dispute && $receipt->event_type !== 'charge.refunded') throw new \RuntimeException('Private charge callback unsupported.');
        $chargeId = $dispute ? ($object['charge'] ?? null) : ($object['id'] ?? null);
        if (!is_string($chargeId) || !preg_match('/^ch_[A-Za-z0-9_]{1,200}$/D', $chargeId)) throw new \RuntimeException('Private charge identity unavailable.');
        try { $provider = RefundProviderFacts::read(app(StripeRefundGateway::class)->retrieveCharge($chargeId)); }
        catch (\Throwable) { throw new \RuntimeException('Private Stripe charge verification unavailable.'); }
        $valid = RefundProviderFacts::validCharge($provider, $chargeId)
            && (!$receipt->stripe_account_id || $receipt->stripe_account_id === $provider['account'])
            && (($object['payment_intent'] ?? null) === null || $object['payment_intent'] === $provider['payment']['id']);
        $identity = $valid ? 'stripe:test:'.$provider['account'].':'.$provider['payment']['id'] : null;
        $hint = $identity ? LedgerEntry::where('external_identity_key', $identity)->first() : null;
        if ($hint && !ImportedProjectLink::where('event_id', $hint->event_id)->exists()) $hint = null;
        DB::transaction(function () use ($receipt, $provider, $valid, $hint, $identity, $dispute, $chargeId) {
            $parent = null;
            if ($hint) {
                Event::whereKey($hint->event_id)->lockForUpdate()->firstOrFail();
                $p = LedgerEntry::whereKey($hint->id)->lockForUpdate()->first();
                if ($p && $p->external_identity_key === $identity && $p->kind === 'receipt' && $p->method === 'stripe'
                    && $p->verified_by === null && $p->source_type === 'verified_stripe_webhook' && $p->environment === 'test' && $p->currency === 'USD'
                    && $p->amount_cents === $provider['charge']['amount'] && $p->external_reference === $provider['payment']['id']
                    && $p->received_at?->timestamp === $provider['charge']['created']
                    && ImportedProjectLink::where('event_id', $p->event_id)->sharedLock()->first()) $parent = $p;
            }
            $locked = WebhookReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->state, ['processed','ignored','reconciliation_required'], true)) return;
            foreach (['raw_sha256','encrypted_payload','event_type','stripe_account_id','verified_at'] as $f)
                if ($locked->getRawOriginal($f) !== $receipt->getRawOriginal($f)) throw new \RuntimeException('Private charge callback changed.');
            if (!$locked->verified_at || $locked->verified_at->isFuture()) throw new \RuntimeException('Private charge callback unverified.');
            $reason = !$valid ? 'charge_provider_binding_invalid' : (!$parent ? 'charge_source_parent_required' : ($dispute ? 'charge_dispute_review_required' : 'charge_refund_details_pending'));
            $facts = ['schema_version' => 1, 'reason' => $reason, 'provider' => $provider, 'parent_ledger_entry_id' => $parent?->id,
                'parent_ledger_sha256' => $parent ? MutationKey::hash(EvidenceV1::model($parent, 'ledger')) : null,
                'webhook' => ['id' => $locked->id, 'event_type' => $locked->event_type, 'raw_sha256' => $locked->raw_sha256,
                    'stripe_account_id' => $locked->stripe_account_id, 'verified_at' => CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $locked->getRawOriginal('verified_at'), 'UTC')->toIso8601String(),
                    'sanitized_payload_sha256' => MutationKey::hash(json_decode(Crypt::decryptString($locked->encrypted_payload), true, flags: JSON_THROW_ON_ERROR))]];
            $o = OpeningRefundObservation::create(['webhook_receipt_id' => $locked->id, 'parent_ledger_entry_id' => $parent?->id,
                'payload' => $facts, 'payload_sha256' => MutationKey::hash($facts)]);
            if ($parent) DB::table('payment_exceptions')->insertOrIgnore(['event_id' => $parent->event_id, 'financial_revision_id' => $parent->financial_revision_id,
                'kind' => 'opening_provider_review', 'exception_key' => hash('sha256', $parent->event_id.':'.$provider['account'].':'.$chargeId.':provider-control'), 'state' => 'open',
                'context' => json_encode(['parent_ledger_entry_id' => $parent->id, 'observation_id' => $o->id, 'reason' => $reason], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            Audit::recordSystem('opening.charge_control_observed', $o, [], ['parent_ledger_entry_id' => $parent?->id, 'reason' => $reason, 'payload_sha256' => $o->payload_sha256]);
            $locked->fill(['state' => 'processed', 'processed_at' => now(), 'last_error' => null, 'attempts' => $locked->attempts + 1])->save();
        }, 3);
    }
}
