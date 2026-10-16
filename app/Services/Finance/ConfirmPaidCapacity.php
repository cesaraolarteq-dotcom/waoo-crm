<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, FinancialRevision, LedgerEntry, User};
use App\Services\{Audit, ConfirmBooking};
use App\Services\Records\RecordActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmPaidCapacity
{
    public function execute(User $actor, int $eventId, array $input): Event
    {
        Sandbox::require(); $actor = RecordActor::authorize($actor);
        $data = CapacityPaymentInput::validate($input);
        try {
            return DB::transaction(function () use ($actor, $eventId, $data) {
                $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
                $revision = FinancialRevision::where('event_id', $eventId)->whereKey($data['financial_revision_id'])->firstOrFail();
                $link = DB::table('capacity_payment_links')->where('event_id', $eventId)->first();
                if ($link) {
                    RecordWriteRejected::unless((int) $link->financial_revision_id === $revision->id, 409, 'La reserva ya está vinculada a otra revisión financiera.');
                    $payment = ['method' => $link->method, 'reference' => $link->reference, 'amount_cents' => (int) $link->amount_cents, 'received_at' => $link->received_at];
                    return app(ConfirmBooking::class)->execute($eventId, [...$data, 'payment' => $payment], $actor);
                }
                RecordWriteRejected::unless($event->status === 'draft' && $event->version === (int) $data['expected_version'] && $revision->event_version === $event->version
                    && (int) FinancialRevision::where('event_id', $eventId)->max('revision') === $revision->revision, 409, 'El evento o sus importes cambiaron. Actualiza antes de asignar recursos.');
                $receipts = LedgerEntry::where('event_id', $eventId)->where('environment', 'test')->where('kind', 'receipt')->orderBy('id')->get();
                $net = $receipts->sum('amount_cents') - (int) LedgerEntry::where('event_id', $eventId)->where('environment', 'test')->where('kind', 'refund')->sum('amount_cents');
                if ($net <= 0) throw ValidationException::withMessages(['booking' => 'Primero registra un pago recibido y verificado.']);
                $payment = ['method' => $receipts->every(fn ($entry) => $entry->method === 'zelle') ? 'external_zelle' : 'external_card',
                    'reference' => 'ledger:test:'.$eventId.':'.$revision->id, 'amount_cents' => $net, 'received_at' => $receipts->sortByDesc('received_at')->first()->received_at];
                $saved = app(ConfirmBooking::class)->execute($eventId, [...$data, 'payment' => $payment], $actor);
                DB::table('capacity_payment_links')->insert(['event_id' => $eventId, 'financial_revision_id' => $revision->id, 'ledger_entry_ids' => json_encode($receipts->pluck('id')->all(), JSON_THROW_ON_ERROR),
                    ...$payment, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('payment_exceptions')->where('event_id', $eventId)->where('financial_revision_id', $revision->id)->where('kind', 'paid_capacity_pending')->update(['state' => 'resolved', 'updated_at' => now()]);
                Audit::recordFor($actor, 'payment.capacity_linked', $saved, [], ['financial_revision_id' => $revision->id, 'ledger_entry_ids' => $receipts->pluck('id')->all(), 'amount_cents' => $net]);
                return $saved;
            }, 3);
        } catch (ValidationException|RecordWriteRejected $e) {
            // The receipt was committed earlier and must survive failed capacity assignment.
            if (LedgerEntry::where('event_id', $eventId)->exists()) {
                $identity = hash('sha256', $eventId.':'.$data['financial_revision_id'].':capacity');
                DB::table('payment_exceptions')->insertOrIgnore(['event_id' => $eventId, 'financial_revision_id' => $data['financial_revision_id'], 'kind' => 'paid_capacity_pending',
                    'exception_key' => $identity, 'state' => 'open', 'context' => json_encode(['message' => $e->getMessage()], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
            throw $e;
        }
    }
}
