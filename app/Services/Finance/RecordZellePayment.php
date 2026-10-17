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
            'received_at' => ['required', 'date', 'before_or_equal:now', new ExplicitOffset], 'verified_received' => 'required|accepted', 'note' => 'nullable|string|max:2000']);
        $data['amount_cents'] = (int) $data['amount_cents']; $data['financial_revision_id'] = (int) $data['financial_revision_id'];
        $data['received_at'] = CarbonImmutable::parse($data['received_at'])->utc()->startOfSecond()->toIso8601String();
        $data['verified_received'] = true;
        $scope = "owner:{$actor->id}:event:{$eventId}:zelle"; $hash = MutationKey::hash($data);
        $identity = 'zelle:test:'.hash('sha256', mb_strtolower($data['reference']));
        try {
            return DB::transaction(function () use ($actor, $eventId, $data, $scope, $hash, $key, $identity) {
                $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
                \App\Services\Imports\ImportedAgreement::blockCrmLifecycle($eventId);
                if ($saved = MutationKey::find($scope, $key, $hash)) return LedgerEntry::findOrFail($saved->result_id);
                $revision = FinancialRevision::where('event_id', $eventId)->whereKey($data['financial_revision_id'])->firstOrFail();
                $existing = LedgerEntry::where('external_identity_key', $identity)->first();
                if ($existing) {
                    RecordWriteRejected::unless($existing->event_id === $eventId && $existing->amount_cents === $data['amount_cents'] && $existing->currency === $data['currency']
                        && $existing->received_at->equalTo($data['received_at']), 409, 'Esta referencia ya tiene otro pago registrado.');
                    MutationKey::store($scope, $key, $hash, 'ledger_entry', $existing->id);
                    return $existing;
                }
                $latest = FinancialRevision::where('event_id', $eventId)->orderByDesc('revision')->firstOrFail();
                RecordWriteRejected::unless($latest->id === $revision->id, 409, 'La revisión financiera cambió. Actualiza antes de registrar el pago.');
                // Once capacity is reserved its version legitimately changes; receipts still belong to its agreement.
                RecordWriteRejected::unless($event->status !== 'draft' || $event->version === $revision->event_version, 409, 'Los datos del evento cambiaron; revisa sus importes.');
                $entry = LedgerEntry::create(['event_id' => $eventId, 'financial_revision_id' => $revision->id, 'environment' => 'test', 'kind' => 'receipt', 'method' => 'zelle',
                    'amount_cents' => $data['amount_cents'], 'currency' => 'USD', 'received_at' => $data['received_at'], 'external_reference' => $data['reference'],
                    'external_identity_key' => $identity, 'verified_by' => $actor->id, 'source_type' => 'owner_verified', 'note' => $data['note'] ?? null]);
                SupersedeCollections::forEvent($eventId, 'external_payment_received', $actor);
                $summary = app(FinanceSummary::class)->forEvent($event);
                if ($summary['credit_cents'] > 0) DB::table('payment_exceptions')->insertOrIgnore(['event_id' => $eventId, 'financial_revision_id' => $revision->id, 'kind' => 'overpayment',
                    'exception_key' => hash('sha256', 'zelle:'.$entry->id.':overpayment'), 'state' => 'open', 'context' => json_encode(['ledger_entry_id' => $entry->id, 'credit_cents' => $summary['credit_cents']], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                Audit::recordFor($actor, 'payment.zelle_received', $entry, [], $entry->getAttributes());
                MutationKey::store($scope, $key, $hash, 'ledger_entry', $entry->id);
                return $entry;
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) throw new RecordWriteRejected(409, 'La referencia o solicitud ya se registró. Revisa el historial.');
            throw $e;
        }
    }
}
