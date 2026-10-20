<?php
namespace App\Services\Finance;

use App\Models\{ImportedProjectLink, LedgerEntry, OpeningStripeEvidence, PaymentIntent, WebhookReceipt};
use App\Services\Integrity\EvidenceV1;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Crypt, DB};

/** V1 provider verification capture. Explicit frozen facts, not mutable ORM serialization. */
final class OpeningStripeProof
{
    public static function capture(LedgerEntry $entry, PaymentIntent $intent, WebhookReceipt $receipt, array $session, array $payment): void
    {
        if (!ImportedProjectLink::where('event_id', $entry->event_id)->exists()) return;
        if (DB::transactionLevel() < 1) throw new \LogicException('Stripe evidence requires the receipt transaction.');
        $p = ['schema_version' => 1, 'ledger_id' => $entry->id,
            'ledger_sha256' => MutationKey::hash(EvidenceV1::model($entry->fresh(), 'ledger')),
            'intent' => self::intent($intent->fresh()), 'webhook' => self::webhook($receipt->fresh()),
            'provider' => ['account' => $session['_verified_account'], 'session_id' => $session['id'], 'payment_id' => $payment['id'],
                'gross_cents' => (int)$payment['amount_received'], 'received_at' => $entry->received_at->toIso8601String()]];
        OpeningStripeEvidence::create(['ledger_entry_id' => $entry->id, 'payload' => $p, 'payload_sha256' => MutationKey::hash($p)]);
    }

    public static function inspect(LedgerEntry $entry): array
    {
        $row = OpeningStripeEvidence::where('ledger_entry_id', $entry->id)->sharedLock()->first();
        $failed = ['eligible' => false, 'issue_codes' => ['stripe_allocation_pending', $row ? 'stripe_evidence_invalid' : 'stripe_evidence_required'],
            'principal_cents' => null, 'customer_fee_cents' => null, 'stripe_evidence_sha256' => null];
        if (!$row) return $failed;
        try {
            $p = $row->payload;
            if (!is_array($p) || ($p['schema_version'] ?? null) !== 1 || !hash_equals($row->payload_sha256, MutationKey::hash($p))) return $failed;
            $i = PaymentIntent::whereKey($p['intent']['id'])->sharedLock()->first();
            $w = WebhookReceipt::whereKey($entry->source_id)->sharedLock()->first();
            if (!$i || !$w || $w->state !== 'processed' || !$w->processed_at || !$w->verified_at || $w->verified_at->isFuture()) return $failed;
            $intent = self::intent($i); $webhook = self::webhook($w); $provider = $p['provider'];
            $valid = $p['ledger_id'] === $entry->id && $row->ledger_entry_id === $entry->id
                && $p['ledger_sha256'] === MutationKey::hash(EvidenceV1::model($entry, 'ledger'))
                && $p['intent'] === $intent && $p['webhook'] === $webhook
                && $entry->kind === 'receipt' && $entry->method === 'stripe' && $entry->source_type === 'verified_stripe_webhook'
                && $entry->verified_by === null && $entry->environment === 'test' && $entry->currency === 'USD'
                && $intent['event_id'] === $entry->event_id && $intent['financial_revision_id'] === $entry->financial_revision_id
                && $intent['environment'] === 'test' && $intent['currency'] === 'USD' && in_array($intent['purpose'], ['deposit', 'balance', 'installment'], true)
                && $intent['principal_cents'] > 0 && $intent['customer_fee_cents'] >= 0 && $intent['payable_cents'] <= 100000000
                && $intent['payable_cents'] === $intent['principal_cents'] + $intent['customer_fee_cents']
                && $intent['payable_cents'] === $entry->amount_cents && $provider['gross_cents'] === $entry->amount_cents
                && is_string($provider['account']) && $provider['account'] !== '' && $provider['account'] === config('waoo.stripe_test_account')
                && ($webhook['stripe_account_id'] === null || $webhook['stripe_account_id'] === $provider['account'])
                && in_array($webhook['event_type'], ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'payment_intent.succeeded'], true)
                && $provider['session_id'] === $intent['stripe_checkout_session_id'] && $provider['payment_id'] === $intent['stripe_payment_intent_id']
                && is_string($provider['payment_id']) && str_starts_with($provider['payment_id'], 'pi_')
                && $provider['payment_id'] === $entry->external_reference
                && $entry->external_identity_key === 'stripe:test:'.$provider['account'].':'.$provider['payment_id']
                && $provider['received_at'] === $entry->received_at?->toIso8601String();
            if (!$valid) return $failed;
            return ['eligible' => true, 'issue_codes' => [], 'principal_cents' => $intent['principal_cents'],
                'customer_fee_cents' => $intent['customer_fee_cents'], 'stripe_evidence_sha256' => $row->payload_sha256];
        } catch (\Illuminate\Database\QueryException $e) { throw $e; }
        catch (\Throwable) { return $failed; }
    }

    private static function intent(PaymentIntent $intent): array
    {
        $p = []; $raw = $intent->getRawOriginal();
        foreach (['id', 'event_id', 'financial_revision_id', 'environment', 'purpose', 'currency', 'principal_cents', 'customer_fee_cents', 'payable_cents', 'stripe_checkout_session_id', 'stripe_payment_intent_id'] as $key) {
            if (!array_key_exists($key, $raw)) throw new \LogicException('Missing Stripe intent evidence field.');
            $p[$key] = in_array($key, ['event_id', 'financial_revision_id', 'principal_cents', 'customer_fee_cents', 'payable_cents'], true) ? (int)$raw[$key] : $raw[$key];
        }
        return $p;
    }
    private static function webhook(WebhookReceipt $receipt): array
    {
        $p = []; $raw = $receipt->getRawOriginal();
        foreach (['id', 'event_type', 'raw_sha256', 'stripe_account_id', 'verified_at'] as $key) {
            if (!array_key_exists($key, $raw)) throw new \LogicException('Missing Stripe webhook evidence field.');
            $p[$key] = $key === 'verified_at' ? CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $raw[$key], 'UTC')->toIso8601String() : $raw[$key];
        }
        $p['sanitized_payload_sha256'] = MutationKey::hash(json_decode(Crypt::decryptString($raw['encrypted_payload']), true, flags: JSON_THROW_ON_ERROR));
        return $p;
    }
}
