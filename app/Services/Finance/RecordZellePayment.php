<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, FinancialRevision, LedgerEntry, User};
use App\Services\Audit;
use App\Services\Records\{RecordActor, RecordInput};
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class RecordZellePayment
{
    public function execute(User $actor, int $eventId, array $input, string $key): LedgerEntry
    {
        Sandbox::require(); $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['financial_revision_id' => 'required|integer|min:1', 'amount_cents' => 'required|integer|min:1|max:100000000',
            'currency' => ['required', Rule::in(['USD'])], 'reference' => 'nullable|string|min:3|max:255',
            ...ZelleReceivedDate::rules(), 'verified_received' => 'required|accepted', 'note' => 'nullable|string|max:2000']);
        $data['amount_cents'] = (int) $data['amount_cents']; $data['financial_revision_id'] = (int) $data['financial_revision_id'];
        if (isset($data['received_at'])) $data['received_at'] = CarbonImmutable::parse($data['received_at'])->utc()->startOfSecond()->toIso8601String();
        $data['verified_received'] = true;
        $data['reference'] = $data['reference'] ?? null;
        $scope = "owner:{$actor->id}:event:{$eventId}:zelle"; $hash = MutationKey::hash($data);
        [$dayStart, $dayEnd] = ZelleReceivedDate::bounds($data);
        $hasReference = filled($data['reference'] ?? null);
        $identity = $hasReference ? 'zelle:test:'.hash('sha256', mb_strtolower($data['reference']))
            : 'zelle:day:'.hash('sha256', implode(':', [$eventId, $data['amount_cents'], $data['currency'], $dayStart->toIso8601String()]));
        try {
            return DB::transaction(function () use ($actor, $eventId, $data, $scope, $hash, $key, $identity, $hasReference, $dayStart, $dayEnd) {
                $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
                \App\Services\Responsibility\RequireCrmResponsibility::check($event);
                \App\Services\Imports\ImportedAgreement::blockCrmLifecycle($eventId);
                if ($saved = MutationKey::find($scope, $key, $hash)) return LedgerEntry::findOrFail($saved->result_id);
                $quick=\App\Services\Booking\QuickPaymentProtocol::booking($eventId);
                $pendingLease=$quick?\App\Models\QuickStripeLease::whereKey($quick->quick_stripe_lease_id)->lockForUpdate()->first():null;
                if($quick && $quick->quick_booking_id===null && ($quick->receipt_id===null || $pendingLease?->state==='active')) {
                    \App\Services\Booking\QuickPaymentProtocol::gate();\App\Services\Booking\QuickPaymentProtocol::verify($quick,$event);
                    $prior=$quick->receipt_id?LedgerEntry::whereKey($quick->receipt_id)->sharedLock()->first():null;
                    $priorValid=$prior && $prior->event_id===$eventId && $prior->financial_revision_id===$quick->financial_revision_id && $prior->kind==='receipt' && $prior->environment==='test'
                        && $prior->amount_cents>0 && (($prior->source_type==='owner_verified' && $prior->verified_by!==null) || \App\Services\Booking\QuickCostumeHolds::verifiedStripeReceipt($prior));
                    RecordWriteRejected::unless($priorValid || ($quick->receipt_id===null && \App\Services\Booking\QuickPaymentProtocol::pendingNotice($quick)),409,'Revisa primero el aviso de Zelle o el pago real de esta reserva.');
                    $p=\App\Services\Booking\QuickStripeLeases::verify($pendingLease,$event,false,(bool)$priorValid);\App\Services\Booking\QuickStripeLeases::lockInventory($p);\App\Services\Booking\QuickStripeLeases::verify($pendingLease,$event,true,(bool)$priorValid);
                } else \App\Services\Booking\QuickStripeLeases::assertNoActive($eventId);
                $receivedAt = ZelleReceivedDate::timestamp($data);
                if (!$hasReference) {
                    $duplicate = LedgerEntry::where('event_id', $eventId)->where('environment', 'test')->where('kind', 'receipt')->where('method', 'zelle')
                        ->where('amount_cents', $data['amount_cents'])->where('currency', $data['currency'])
                        ->where('received_at', '>=', $dayStart)->where('received_at', '<', $dayEnd)->exists();
                    RecordWriteRejected::unless(!$duplicate, 409, 'Hay un pago del mismo monto en esta fecha. Revisa el historial; si es otro pago, usa su referencia bancaria real.');
                }
                $revision = FinancialRevision::where('event_id', $eventId)->whereKey($data['financial_revision_id'])->firstOrFail();
                $existing = LedgerEntry::where('external_identity_key', $identity)->first();
                if ($existing) {
                    RecordWriteRejected::unless($existing->event_id === $eventId && $existing->amount_cents === $data['amount_cents'] && $existing->currency === $data['currency']
                        && $existing->received_at->equalTo($receivedAt), 409, 'Esta referencia ya tiene otro pago registrado.');
                    MutationKey::store($scope, $key, $hash, 'ledger_entry', $existing->id);
                    return $existing;
                }
                $latest = FinancialRevision::where('event_id', $eventId)->orderByDesc('revision')->firstOrFail();
                RecordWriteRejected::unless($latest->id === $revision->id, 409, 'La revisión financiera cambió. Actualiza antes de registrar el pago.');
                // Once capacity is reserved its version legitimately changes; receipts still belong to its agreement.
                RecordWriteRejected::unless($event->status !== 'draft' || $event->version === $revision->event_version, 409, 'Los datos del evento cambiaron; revisa sus importes.');
                $entry = LedgerEntry::create(['event_id' => $eventId, 'financial_revision_id' => $revision->id, 'environment' => 'test', 'kind' => 'receipt', 'method' => 'zelle',
                    'amount_cents' => $data['amount_cents'], 'currency' => 'USD', 'received_at' => $receivedAt, 'external_reference' => $data['reference'] ?? null,
                    'external_identity_key' => $identity, 'verified_by' => $actor->id, 'source_type' => 'owner_verified', 'note' => $data['note'] ?? null]);
                if($quick && $quick->quick_booking_id===null) app(\App\Services\Booking\PromoteQuickPayment::class)->execute($quick,$event,$entry,$actor);
                SupersedeCollections::forEvent($eventId, 'external_payment_received', $actor);
                $summary = app(FinanceSummary::class)->forEvent($event);
                if ($summary['credit_cents'] > 0) DB::table('payment_exceptions')->insertOrIgnore(['event_id' => $eventId, 'financial_revision_id' => $revision->id, 'kind' => 'overpayment',
                    'exception_key' => hash('sha256', 'zelle:'.$entry->id.':overpayment'), 'state' => 'open', 'context' => json_encode(['ledger_entry_id' => $entry->id, 'credit_cents' => $summary['credit_cents']], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                Audit::recordFor($actor, 'payment.zelle_received', $entry, [], $entry->getAttributes());
                MutationKey::store($scope, $key, $hash, 'ledger_entry', $entry->id);
                app(\App\Services\Mail\CaptureMailOccurrence::class)->record('payment_received', $entry->id, ['event_id'=>$event->id, 'customer_id'=>$event->customer_id, 'financial_revision_id'=>$revision->id, 'ledger_entry_id'=>$entry->id], $actor);
                return $entry;
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) throw new RecordWriteRejected(409, 'La referencia o solicitud ya se registró. Revisa el historial.');
            throw $e;
        }
    }
}
