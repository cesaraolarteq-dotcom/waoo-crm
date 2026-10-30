<?php
namespace App\Services\Changes;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, LedgerEntry, RefundRequest, User};
use App\Services\Audit;
use App\Services\Finance\{Sandbox, StripeRefunds};
use App\Services\Records\{RecordActor, RecordInput};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-10-29: money goes back by the method it came in. A card payment is refunded through Stripe from here;
 * a Zelle payment is sent back by the owner from the bank and only recorded here. Refunds are rare and always the
 * owner's decision (the deposit is non-refundable by contract). Only the payment itself is refunded, never more than
 * what is left of it; the 3% card fee line is not touched (Codex: confirm).
 * The request key is the Stripe idempotency key: repeating the same request after a lost answer returns the same refund.
 */
final class RefundPayment
{
    public function execute(User $actor, int $eventId, array $input, string $key): RefundRequest
    {
        Sandbox::require(); Sandbox::require('sandbox_changes'); $actor = RecordActor::authorize($actor);
        RecordWriteRejected::unless(DB::transactionLevel() === 0, 409, 'El reembolso no puede ir dentro de otra operación.');
        $data = RecordInput::validate([...$input, 'request_key' => $key], ['request_key' => 'required|uuid',
            'receipt_id' => 'required|integer|min:1', 'amount_cents' => 'required|integer|min:1|max:100000000', 'reason' => 'required|string|min:10|max:1000',
            'sent_on' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'reference' => 'nullable|string|min:3|max:255', 'confirm_sent' => 'nullable|boolean']);
        $data['amount_cents'] = (int) $data['amount_cents']; $data['receipt_id'] = (int) $data['receipt_id']; $data['reason'] = trim($data['reason']);

        $request = DB::transaction(function () use ($actor, $eventId, $data, $key) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            \App\Services\Responsibility\RequireCrmResponsibility::check($event);
            if ($existing = RefundRequest::where('request_key', $key)->lockForUpdate()->first()) {
                RecordWriteRejected::unless($existing->event_id === $eventId && $existing->receipt_id === $data['receipt_id'] && $existing->amount_cents === $data['amount_cents'], 409, 'Esta clave ya se usó con otros datos.');
                return $existing;
            }
            $receipt = LedgerEntry::whereKey($data['receipt_id'])->where('event_id', $eventId)->where('kind', 'receipt')->where('environment', 'test')->first();
            RecordWriteRejected::unless($receipt && in_array($receipt->method, ['stripe', 'zelle'], true), 409, 'Ese pago no es de esta fiesta o no se puede devolver desde aquí.');
            $committed = (int) RefundRequest::where('receipt_id', $receipt->id)->whereIn('state', ['requested', 'unknown', 'succeeded'])->lockForUpdate()->sum('amount_cents');
            RecordWriteRejected::unless($data['amount_cents'] <= $receipt->amount_cents - $committed, 422, 'El monto es mayor que lo que queda de ese pago ('.number_format(($receipt->amount_cents - $committed) / 100, 2).' USD).');
            if ($receipt->method === 'zelle') {
                RecordWriteRejected::unless(($data['confirm_sent'] ?? false) === true && isset($data['sent_on']), 422, 'Primero manda el Zelle de vuelta desde el banco; luego marca que lo enviaste y la fecha.');
                $request = RefundRequest::create(['request_key' => $key, 'event_id' => $eventId, 'receipt_id' => $receipt->id, 'method' => 'zelle',
                    'amount_cents' => $data['amount_cents'], 'reason' => $data['reason'], 'state' => 'succeeded', 'requested_by' => $actor->id]);
                $this->record($actor, $request, $receipt, CarbonImmutable::parse($data['sent_on'].' 12:00', 'America/Chicago')->utc(), $data['reference'] ?? null, 'refund:zelle:'.$key);
                return $request;
            }
            $request = RefundRequest::create(['request_key' => $key, 'event_id' => $eventId, 'receipt_id' => $receipt->id, 'method' => 'stripe',
                'amount_cents' => $data['amount_cents'], 'reason' => $data['reason'], 'state' => 'requested', 'requested_by' => $actor->id]);
            Audit::recordFor($actor, 'payment.refund_requested', $request, [], ['receipt_id' => $receipt->id, 'amount_cents' => $request->amount_cents, 'method' => 'stripe']);
            return $request;
        }, 3);
        if ($request->method !== 'stripe' || ! in_array($request->state, ['requested', 'unknown'], true)) return $request;

        $receipt = LedgerEntry::findOrFail($request->receipt_id);
        try {
            $refund = app(StripeRefunds::class)->refund((string) $receipt->external_reference, $request->amount_cents, $request->request_key);
        } catch (\Stripe\Exception\InvalidRequestException|\Stripe\Exception\CardException $e) {
            RefundRequest::whereKey($request->id)->whereIn('state', ['requested', 'unknown'])->update(['state' => 'failed', 'error_code' => mb_substr((string) ($e->getStripeCode() ?: 'stripe_rejected'), 0, 100)]);
            throw new RecordWriteRejected(422, 'Stripe no aceptó el reembolso. No se devolvió nada.');
        } catch (\Throwable) {
            RefundRequest::whereKey($request->id)->where('state', 'requested')->update(['state' => 'unknown', 'error_code' => 'provider_outcome_unknown']);
            throw new RecordWriteRejected(503, 'No se supo la respuesta de Stripe. Repite el mismo reembolso; no se cobrará dos veces.');
        }
        if (! in_array($refund['status'], ['succeeded', 'pending'], true) || $refund['amount'] !== $request->amount_cents || $refund['payment_intent'] !== $receipt->external_reference) {
            RefundRequest::whereKey($request->id)->update(['state' => 'failed', 'error_code' => 'refund_'.$refund['status']]);
            throw new RecordWriteRejected(422, 'Stripe no completó el reembolso. Revisa Stripe antes de intentar otra vez.');
        }
        return DB::transaction(function () use ($actor, $request, $receipt, $refund) {
            Event::whereKey($request->event_id)->lockForUpdate()->firstOrFail();
            $locked = RefundRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->state === 'succeeded') return $locked;
            $locked->fill(['state' => 'succeeded', 'provider_refund_id' => $refund['id'], 'error_code' => null])->save();
            $this->record($actor, $locked, $receipt, CarbonImmutable::now()->utc(), $refund['id'], 'refund:stripe:'.$refund['id']);
            return $locked;
        }, 3);
    }

    private function record(User $actor, RefundRequest $request, LedgerEntry $receipt, CarbonImmutable $at, ?string $reference, string $identity): void
    {
        $entry = LedgerEntry::create(['event_id' => $request->event_id, 'financial_revision_id' => $receipt->financial_revision_id, 'environment' => 'test', 'kind' => 'refund',
            'method' => $request->method, 'amount_cents' => $request->amount_cents, 'currency' => 'USD', 'received_at' => $at, 'external_reference' => $reference,
            'external_identity_key' => $identity, 'verified_by' => $actor->id, 'source_type' => 'owner_refund', 'source_id' => (string) $request->id, 'note' => $request->reason]);
        RefundRequest::whereKey($request->id)->update(['ledger_entry_id' => $entry->id]);
        $request->ledger_entry_id = $entry->id;
        Audit::recordFor($actor, 'payment.refunded', $entry, [], ['receipt_id' => $receipt->id, 'amount_cents' => $entry->amount_cents, 'method' => $entry->method]);
    }
}
