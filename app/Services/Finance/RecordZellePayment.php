<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, FinancialRevision, LedgerEntry, User};
use App\Rules\ExplicitOffset;
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
            'currency' => ['required', Rule::in(['USD'])], 'reference' => 'required|string|min:3|max:255',
            'received_at' => ['required', 'date', 'before_or_equal:now', new ExplicitOffset], 'verified_received' => 'required|accepted', 'note' => 'nullable|string|max:2000',
            'opening_id' => 'sometimes|integer|min:1', 'expected_collection_sha256' => 'sometimes|string|regex:/^[a-f0-9]{64}$/D', 'post_cutover_received' => 'sometimes|accepted']);
        $data['amount_cents'] = (int) $data['amount_cents']; $data['financial_revision_id'] = (int) $data['financial_revision_id'];
        $data['received_at'] = CarbonImmutable::parse($data['received_at'])->utc()->startOfSecond()->toIso8601String();
        $data['verified_received'] = true;
        if (isset($data['opening_id'])) $data['opening_id'] = (int)$data['opening_id'];
        if (isset($data['post_cutover_received'])) $data['post_cutover_received'] = true;
        $scope = "owner:{$actor->id}:event:{$eventId}:zelle"; $hash = MutationKey::hash($data);
        $identity = 'zelle:test:'.hash('sha256', mb_strtolower($data['reference']));
        try {
            return DB::transaction(function () use ($actor, $eventId, $data, $scope, $hash, $key, $identity) {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); RecordActor::authorize($actor, true);
                $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
                \App\Services\Responsibility\RequireCrmResponsibility::check($event);
                $opening = OpeningReceiptContext::receipt($event, $data);
                if ($saved = MutationKey::find($scope, $key, $hash, true)) {
                    $entry = LedgerEntry::whereKey($saved->result_id)->lockForUpdate()->firstOrFail();
                    if ($opening) OpeningReceiptLink::attach($actor, $entry, $opening);
                    return $entry;
                }
                $revision = FinancialRevision::where('event_id', $eventId)->whereKey($data['financial_revision_id'])->sharedLock()->firstOrFail();
                $existing = LedgerEntry::where('external_identity_key', $identity)->lockForUpdate()->first();
                if ($existing) {
                    RecordWriteRejected::unless($existing->event_id === $eventId && $existing->amount_cents === $data['amount_cents'] && $existing->currency === $data['currency']
                        && $existing->received_at->equalTo($data['received_at']), 409, 'Esta referencia ya tiene otro pago registrado.');
                    if ($opening) OpeningReceiptLink::attach($actor, $existing, $opening);
                    MutationKey::store($scope, $key, $hash, 'ledger_entry', $existing->id);
                    return $existing;
                }
                $latest = FinancialRevision::where('event_id', $eventId)->orderByDesc('revision')->sharedLock()->firstOrFail();
                RecordWriteRejected::unless($latest->id === $revision->id, 409, 'La revisión financiera cambió. Actualiza antes de registrar el pago.');
                // Once capacity is reserved its version legitimately changes; receipts still belong to its agreement.
                RecordWriteRejected::unless($event->status !== 'draft' || $event->version === $revision->event_version, 409, 'Los datos del evento cambiaron; revisa sus importes.');
                $entry = LedgerEntry::create(['event_id' => $eventId, 'financial_revision_id' => $revision->id, 'environment' => 'test', 'kind' => 'receipt', 'method' => 'zelle',
                    'amount_cents' => $data['amount_cents'], 'currency' => 'USD', 'received_at' => $data['received_at'], 'external_reference' => $data['reference'],
                    'external_identity_key' => $identity, 'verified_by' => $actor->id, 'source_type' => 'owner_verified', 'note' => $data['note'] ?? null]);
                SupersedeCollections::forEvent($eventId, 'external_payment_received', $actor);
                Audit::recordFor($actor, 'payment.zelle_received', $entry, [], $entry->getAttributes());
                if ($opening) OpeningReceiptLink::attach($actor, $entry, $opening);
                $summary = $opening ? OpeningFinanceSummary::forEvent($event) : app(FinanceSummary::class)->forEvent($event);
                if ($summary['credit_cents'] > 0) DB::table('payment_exceptions')->insertOrIgnore(['event_id' => $eventId, 'financial_revision_id' => $revision->id, 'kind' => 'overpayment',
                    'exception_key' => hash('sha256', 'zelle:'.$entry->id.':overpayment'), 'state' => 'open', 'context' => json_encode(['ledger_entry_id' => $entry->id, 'credit_cents' => $summary['credit_cents']], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                MutationKey::store($scope, $key, $hash, 'ledger_entry', $entry->id);
                if (!$opening) app(\App\Services\Mail\CaptureMailOccurrence::class)->record('payment_received', $entry->id, ['event_id'=>$event->id, 'customer_id'=>$event->customer_id, 'financial_revision_id'=>$revision->id, 'ledger_entry_id'=>$entry->id], $actor);
                return $entry;
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) throw new RecordWriteRejected(409, 'La referencia o solicitud ya se registró. Revisa el historial.');
            if (isset($data['opening_id'])) throw new RecordWriteRejected(503, 'No se pudo guardar el recibo importado. Reintenta la misma solicitud.');
            throw $e;
        }
    }
}
