<?php
namespace App\Services\Changes;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Contract, Event, EventChange, FinancialRevision, QuickCostumeHold, Resource, ResourceReservation, User};
use App\Services\Audit;
use App\Services\Availability\AdmitEventCapacity;
use App\Services\Booking\QuickCostumeHolds;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Records\{RecordActor, RecordInput};
use App\Support\ChicagoTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CHANGE-A part 1 (César 2026-10-30): move a party to another date/time in one transaction. The same party (package,
 * characters, duration, setup/breakdown, address) moves; every unit it already holds (confirmed reservations and the quick
 * costume hold) must be free at the new time, or nothing changes — the new slot is secured before the old one is let go,
 * because both happen atomically. The 4-at-once / 10-per-day limits are re-checked under the Houston day locks.
 * The $100 fee decision (charge | waive + reason) is recorded here; the money revision (part 2), the new contract (part 3)
 * and the client notices (part 4) follow. A signed contract makes the change "pending signature" until the new one is signed.
 * HoneyBook-managed parties are refused (HoneyBook still owns their lifecycle).
 */
final class RescheduleEvent
{
    public const FEE_CENTS = 10000;

    public function execute(User $actor, int $eventId, array $input, string $key): EventChange
    {
        Sandbox::require(); Sandbox::require('sandbox_changes'); $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['expected_version' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d',
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/D'], 'offset_choice' => 'sometimes|nullable|in:cdt,cst',
            'fee_decision' => 'required|in:charge,waive', 'fee_reason' => 'required|string|min:5|max:1000']);
        $data = [...$data, 'expected_version' => (int) $data['expected_version'], 'fee_reason' => trim($data['fee_reason'])];
        $start = CarbonImmutable::parse(ChicagoTime::toIso($data['date'], $data['time'], $data['offset_choice'] ?? null, 'time'))->utc();
        if ($start <= now()) throw ValidationException::withMessages(['time' => 'Elige una fecha y hora futuras.']);
        $scope = "owner:{$actor->id}:event:{$eventId}:reschedule"; $hash = MutationKey::hash($data);

        return DB::transaction(function () use ($actor, $eventId, $data, $start, $scope, $hash, $key) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($saved = MutationKey::find($scope, $key, $hash)) return EventChange::findOrFail($saved->result_id);
            \App\Services\Responsibility\RequireCrmResponsibility::check($event);
            \App\Services\Imports\ImportedAgreement::blockCrmLifecycle($eventId);
            RecordWriteRejected::unless(in_array($event->status, ['draft', 'capacity_reserved'], true), 409, 'Esta fiesta no se puede cambiar de fecha.');
            RecordWriteRejected::unless($event->version === $data['expected_version'], 409, 'La fiesta cambió. Recarga la página antes de cambiar la fecha.');
            RecordWriteRejected::unless(! EventChange::where('event_id', $eventId)->where('state', 'pending_signature')->exists(), 409,
                'Ya hay un cambio de fecha esperando la firma del cliente.');
            \App\Services\Booking\QuickStripeLeases::assertNoActive($eventId);
            // A card quick-payment booking freezes the original date in its evidence; moving it is not supported yet.
            RecordWriteRejected::unless(! \App\Models\QuickPaymentBooking::where('event_id', $eventId)->whereNotIn('state', ['expired', 'cancelled'])->exists(), 409,
                'Esta reserva se hizo con el enlace de pago con tarjeta: el cambio de fecha para ese tipo de reserva todavía no está listo. Avísale a Claude.');
            $oldStart = $event->starts_at; $oldEnd = $event->ends_at; $before = $event->version;
            $end = $start->addSeconds($oldStart->diffInSeconds($oldEnd));
            RecordWriteRejected::unless(! $start->equalTo($oldStart), 422, 'Esa es la misma fecha y hora de ahora.');

            // Day locks for both the old and the new days, ascending, before any unit lock (AdmitEventCapacity order).
            AdmitEventCapacity::lockWindows([[$oldStart, $oldEnd], [$start, $end]]);
            $hold = QuickCostumeHold::where('event_id', $eventId)->whereIn('state', ['held', 'review_required', 'consumed'])->lockForUpdate()->first();
            $p = $hold ? QuickCostumeHolds::verify($hold, $event) : null;
            $reservations = ResourceReservation::where('event_id', $eventId)->orderBy('resource_id')->lockForUpdate()->get();
            $units = Resource::whereIn('id', $reservations->pluck('resource_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $event->starts_at = $start; $event->ends_at = $end; $event->version = $before + 1; $event->save();
            foreach ($reservations as $r) {
                $unit = $units->get($r->resource_id);
                RecordWriteRejected::unless($unit && $unit->status === 'active' && QuickCostumeHolds::available($event, $unit, $reservations->pluck('id')->all()), 409,
                    'A esa hora '.($unit?->name ?? 'un recurso apartado').' ya está ocupado. Elige otra hora o cambia el apartado primero.');
                $r->forceFill(['starts_at' => $start, 'ends_at' => $end])->save();
            }
            AdmitEventCapacity::assertFits($event);
            if ($hold) QuickCostumeHolds::transition($actor, $event, $hold, $hold->state, $p['rows'], $p['issues'], 'Cambio de fecha: '.$data['fee_reason']);

            // Part 2 (money): a new financial revision with the same prices and discounts, the $100 line when charged, and the
            // balance due 24 h before the new start. Payments already received stay; open payment links are superseded.
            $finance = $this->reviseFinance($actor, $event, $data['fee_decision'] === 'charge');
            $signed = Contract::where('event_id', $eventId)->where('state', 'signed')->exists();
            $change = EventChange::create(['event_id' => $eventId, 'kind' => 'reschedule', 'state' => $signed ? 'pending_signature' : 'completed',
                'old_starts_at' => $oldStart, 'old_ends_at' => $oldEnd, 'new_starts_at' => $start, 'new_ends_at' => $end,
                'event_version_before' => $before, 'event_version_after' => $event->version,
                'fee_decision' => $data['fee_decision'], 'fee_reason' => $data['fee_reason'], 'fee_cents' => $data['fee_decision'] === 'charge' ? self::FEE_CENTS : 0,
                'moved_reservations' => $reservations->count(), 'financial_revision_id' => $finance?->id, 'created_by' => $actor->id]);
            Audit::recordFor($actor, 'event.rescheduled', $event, ['starts_at' => $oldStart->toIso8601String(), 'ends_at' => $oldEnd->toIso8601String(), 'version' => $before],
                ['starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(), 'version' => $event->version, 'event_change_id' => $change->id,
                 'fee_decision' => $data['fee_decision'], 'moved_reservations' => $reservations->count()]);
            MutationKey::store($scope, $key, $hash, 'event_change', $change->id);
            return $change;
        }, 3);
    }

    private function reviseFinance(User $actor, Event $event, bool $fee): ?FinancialRevision
    {
        $prev = FinancialRevision::where('event_id', $event->id)->orderByDesc('revision')->lockForUpdate()->first();
        if (! $prev) return null; // nothing priced yet: the first revision will be created as usual
        $lines = (array) $prev->lines_snapshot;
        if ($fee) $lines[] = ['kind' => 'change_fee', 'name' => 'Cargo por cambio de fecha', 'long_description' => null, 'quantity' => 1,
            'amount_cents' => self::FEE_CENTS, 'duration_minutes' => 0];
        $copy = collect($prev->getAttributes())->except(['id', 'revision', 'event_version', 'lines_snapshot', 'subtotal_cents', 'total_cents', 'balance_due_at', 'created_by', 'created_at', 'updated_at'])->all();
        foreach (['lines_snapshot', 'discounts_snapshot'] as $json) if (array_key_exists($json, $copy) && is_string($copy[$json])) $copy[$json] = json_decode($copy[$json], true);
        $revision = FinancialRevision::create([...$copy, 'revision' => $prev->revision + 1, 'event_version' => $event->version, 'lines_snapshot' => $lines,
            'subtotal_cents' => $prev->subtotal_cents + ($fee ? self::FEE_CENTS : 0), 'total_cents' => $prev->total_cents + ($fee ? self::FEE_CENTS : 0),
            'balance_due_at' => $event->starts_at->subHours(24), 'created_by' => $actor->id]);
        \App\Services\Finance\SupersedeCollections::forEvent($event->id, 'financial_revision_changed', $actor);
        Audit::recordFor($actor, 'finance.revision_created', $revision, [], ['event_id' => $event->id, 'reason' => 'reschedule', 'change_fee_cents' => $fee ? self::FEE_CENTS : 0, 'total_cents' => $revision->total_cents]);
        return $revision;
    }
}
