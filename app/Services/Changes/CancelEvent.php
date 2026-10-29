<?php
namespace App\Services\Changes;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, EventCancellation, EventMessagePolicy, QuickCostumeHold, ResourceReservation, User};
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox, SupersedeCollections};
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-10-29: cancelling keeps the deposit (non-refundable) and refunds nothing by itself. It frees the staff,
 * costumes and equipment of that date, closes open payment links and pauses the party's reminders. The signed contract,
 * the payments and the history stay. A refund, if the owner ever decides one, is a separate step (RefundPayment).
 */
final class CancelEvent
{
    public function execute(User $actor, int $eventId, array $input, string $key): EventCancellation
    {
        Sandbox::require(); Sandbox::require('sandbox_changes'); $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['expected_version' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:1000']);
        $data = ['expected_version' => (int) $data['expected_version'], 'reason' => trim($data['reason'])];
        $scope = "owner:{$actor->id}:event:{$eventId}:cancel"; $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($actor, $eventId, $data, $scope, $hash, $key) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($saved = MutationKey::find($scope, $key, $hash)) return EventCancellation::findOrFail($saved->result_id);
            \App\Services\Responsibility\RequireCrmResponsibility::check($event);
            \App\Services\Imports\ImportedAgreement::blockCrmLifecycle($eventId);
            RecordWriteRejected::unless(in_array($event->status, ['draft', 'capacity_reserved'], true), 409, 'Esta fiesta ya no se puede cancelar desde aquí.');
            RecordWriteRejected::unless($event->version === $data['expected_version'], 409, 'La fiesta cambió. Recarga la página antes de cancelar.');
            \App\Services\Booking\QuickStripeLeases::assertNoActive($eventId);
            $prior = $event->status;
            $event->status = 'cancelled'; $event->version = $event->version + 1; $event->save();
            // Codex's quick-booking costume holds have their own release protocol; it needs the cancelled status inside this transaction.
            if (QuickCostumeHold::where('event_id', $eventId)->whereIn('state', ['held', 'review_required', 'consumed'])->exists())
                \App\Services\Booking\QuickCostumeHolds::releaseForCancellation($actor, $event, $data['reason']);
            $released = ResourceReservation::where('event_id', $eventId)->delete();
            SupersedeCollections::forEvent($eventId, 'event_cancelled', $actor);
            $policy = EventMessagePolicy::where('event_id', $eventId)->lockForUpdate()->first() ?? new EventMessagePolicy(['event_id' => $eventId, 'version' => 0]);
            if (! $policy->paused) $policy->fill(['paused' => true, 'version' => $policy->version + 1, 'updated_by' => $actor->id])->save();
            $cancellation = EventCancellation::create(['event_id' => $eventId, 'prior_status' => $prior, 'reason' => $data['reason'],
                'released_reservations' => $released, 'cancelled_by' => $actor->id]);
            Audit::recordFor($actor, 'event.cancelled', $event, ['status' => $prior], ['status' => 'cancelled', 'version' => $event->version, 'released_reservations' => $released]);
            MutationKey::store($scope, $key, $hash, 'event_cancellation', $cancellation->id);
            return $cancellation;
        }, 3);
    }
}
