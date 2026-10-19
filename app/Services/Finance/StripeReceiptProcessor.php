<?php
namespace App\Services\Finance;

use App\Models\{Event, FinancialRevision, LedgerEntry, PaymentIntent, WebhookReceipt};
use App\Services\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Crypt, DB};

final class StripeReceiptProcessor
{
    private function problem(WebhookReceipt $receipt, PaymentIntent $intent, string $reason): void
    {
        DB::transaction(function () use ($receipt, $intent, $reason) {
            Event::whereKey($intent->event_id)->lockForUpdate()->firstOrFail();
            $locked = PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            $locked->fill(['status' => 'reconciliation_required', 'last_error' => $reason])->save();
            DB::table('payment_exceptions')->insertOrIgnore(['event_id' => $intent->event_id, 'financial_revision_id' => $intent->financial_revision_id, 'kind' => 'stripe_reconciliation',
                'exception_key' => hash('sha256', $receipt->id.':'.$reason), 'state' => 'open', 'context' => json_encode(['webhook_id' => $receipt->id, 'intent_id' => $intent->id, 'reason' => $reason], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $receipt->fill(['state' => 'reconciliation_required', 'last_error' => $reason, 'processed_at' => now()])->save();
            Audit::recordSystem('payment.stripe_reconciliation_required', $intent, [], ['webhook_id' => $receipt->id, 'reason' => $reason]);
        }, 3);
    }
    public function execute(string $receiptId): void
    {
        Sandbox::require();
        $receipt = WebhookReceipt::findOrFail($receiptId);
        if (in_array($receipt->state, ['processed','ignored','reconciliation_required'], true)) return;
        $receipt->increment('attempts');
        $payload = json_decode(Crypt::decryptString($receipt->encrypted_payload), true, flags: JSON_THROW_ON_ERROR);
        $object = $payload['data']['object'];
        $intentId = $object['metadata']['local_intent_id'] ?? null;
        $intent = $intentId ? PaymentIntent::find($intentId) : null;
        if (!$intent && !empty($object['payment_intent'])) $intent = PaymentIntent::where('stripe_payment_intent_id', $object['payment_intent'])->first();
        if (!$intent) { $receipt->fill(['state' => 'ignored', 'last_error' => 'unlinked_provider_event', 'processed_at' => now()])->save(); return; }
        if (!in_array($receipt->event_type, ['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed','checkout.session.expired','payment_intent.succeeded'], true)) {
            $this->problem($receipt, $intent, 'unsupported_financial_event'); return;
        }
        $sessionId = ($object['object'] ?? null) === 'checkout.session' ? $object['id'] : $intent->stripe_checkout_session_id;
        if (!$sessionId) throw new \RuntimeException('checkout_not_linked_yet');
        $session = app(StripeGateway::class)->retrieveCheckout($sessionId);
        $metadata = $session['metadata'] ?? [];
        $valid = ($session['id'] ?? null) === $sessionId && ($session['livemode'] ?? null) === false && ($session['_verified_account'] ?? null) === config('waoo.stripe_test_account')
            && (!$receipt->stripe_account_id || $receipt->stripe_account_id === $session['_verified_account'])
            && ($session['client_reference_id'] ?? null) === $intent->id && ($metadata['local_intent_id'] ?? null) === $intent->id
            && (int) ($metadata['event_id'] ?? 0) === $intent->event_id && (int) ($metadata['financial_revision_id'] ?? 0) === $intent->financial_revision_id
            && ($session['currency'] ?? null) === 'usd' && (int) ($session['amount_total'] ?? -1) === $intent->payable_cents
            && (!$intent->stripe_checkout_session_id || $intent->stripe_checkout_session_id === $sessionId);
        if (!$valid) { $this->problem($receipt, $intent, 'session_binding_or_amount_mismatch'); return; }
        if (($session['payment_status'] ?? null) !== 'paid') {
            DB::transaction(function () use ($intent, $receipt, $session) {
                Event::whereKey($intent->event_id)->lockForUpdate()->firstOrFail();
                $locked = PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
                if (!in_array($locked->status, ['paid','superseded','reconciliation_required'], true)) {
                    $expired = ($session['status'] ?? null) === 'expired';
                    $failed = $receipt->event_type === 'checkout.session.async_payment_failed' && in_array($session['payment_intent']['status'] ?? null, ['requires_payment_method','canceled'], true);
                    $locked->fill(['status' => $failed ? 'failed' : ($expired ? 'expired' : 'processing'), 'collection_slot' => ($expired || $failed) ? null : $locked->collection_slot])->save();
                }
                $receipt->fill(['state' => 'processed', 'processed_at' => now(), 'last_error' => null])->save();
            });
            return;
        }
        $payment = $session['payment_intent'] ?? null;
        $charge = is_array($payment) ? ($payment['latest_charge'] ?? null) : null;
        if (!is_array($payment) || !is_array($charge) || ($payment['livemode'] ?? null) !== false || ($payment['status'] ?? null) !== 'succeeded'
            || ($payment['currency'] ?? null) !== 'usd' || (int) ($payment['amount_received'] ?? -1) !== $intent->payable_cents
            || empty($payment['id']) || ($charge['captured'] ?? null) !== true || ($charge['paid'] ?? null) !== true
            || !isset($charge['created']) || (int) $charge['created'] <= 0 || (int) $charge['created'] > time()) {
            $this->problem($receipt, $intent, 'unverified_payment_intent'); return;
        }
        DB::transaction(function () use ($intent, $receipt, $session, $payment, $charge) {
            $event = Event::whereKey($intent->event_id)->lockForUpdate()->firstOrFail();
            $locked = PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            $transaction = 'stripe:test:'.$session['_verified_account'].':'.$payment['id'];
            $existing = LedgerEntry::where('external_identity_key', $transaction)->first();
            if ($existing && ($existing->event_id !== $intent->event_id || $existing->amount_cents !== $intent->payable_cents || $existing->financial_revision_id !== $intent->financial_revision_id)) {
                $this->problem($receipt, $intent, 'transaction_already_allocated'); return;
            }
            if (!$existing) {
                $entry = LedgerEntry::create(['event_id' => $intent->event_id, 'financial_revision_id' => $intent->financial_revision_id, 'environment' => 'test', 'kind' => 'receipt', 'method' => 'stripe',
                    'amount_cents' => $payment['amount_received'], 'currency' => 'USD', 'received_at' => CarbonImmutable::createFromTimestampUTC($charge['created']),
                    'external_reference' => $payment['id'], 'external_identity_key' => $transaction, 'verified_by' => null, 'source_type' => 'verified_stripe_webhook', 'source_id' => $receipt->id]);
                app(\App\Services\Mail\CaptureMailOccurrence::class)->record('payment_received', $entry->id, ['event_id'=>$event->id, 'customer_id'=>$event->customer_id, 'financial_revision_id'=>$intent->financial_revision_id, 'ledger_entry_id'=>$entry->id]);
                Audit::recordSystem('payment.stripe_received', $entry, [], ['intent_id' => $intent->id, 'webhook_id' => $receipt->id, 'amount_cents' => $entry->amount_cents]);
            }
            if ($event->responsible_system !== 'crm') {
                DB::table('payment_exceptions')->insertOrIgnore(['event_id' => $intent->event_id, 'financial_revision_id' => $intent->financial_revision_id,
                    'kind' => 'responsibility_reconciliation', 'exception_key' => hash('sha256', $transaction.':responsibility'), 'state' => 'open',
                    'context' => json_encode(['intent_id' => $intent->id, 'reason' => 'responsible_system_honeybook'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
            $wasSuperseded = $locked->status === 'superseded' || (int) FinancialRevision::where('event_id', $intent->event_id)->max('revision') !== FinancialRevision::findOrFail($intent->financial_revision_id)->revision;
            $locked->fill(['status' => 'paid', 'collection_slot' => null, 'stripe_checkout_session_id' => $session['id'], 'stripe_payment_intent_id' => $payment['id'], 'last_error' => null])->save();
            SupersedeCollections::forEvent($intent->event_id, 'stripe_payment_received', null, $intent->id);
            $receipt->fill(['state' => 'processed', 'processed_at' => now(), 'last_error' => null])->save();
            DB::table('payment_exceptions')->where('event_id', $intent->event_id)->where('kind', 'stripe_reconciliation')->where('context->webhook_id', $receipt->id)->update(['state' => 'resolved', 'updated_at' => now()]);
            $summary = app(FinanceSummary::class)->forEvent($event);
            if ($wasSuperseded || $summary['credit_cents'] > 0) {
                DB::table('payment_exceptions')->insertOrIgnore(['event_id' => $intent->event_id, 'financial_revision_id' => $intent->financial_revision_id,
                    'kind' => $summary['credit_cents'] > 0 ? 'overpayment' : 'paid_superseded_checkout', 'exception_key' => hash('sha256', $transaction.':late'), 'state' => 'open',
                    'context' => json_encode(['intent_id' => $intent->id, 'credit_cents' => $summary['credit_cents']], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
        }, 3);
    }
}
