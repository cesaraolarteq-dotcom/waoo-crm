<?php
namespace App\Services\Booking;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, QuickCostumeHold, QuickCostumeHoldEvent, Resource, ResourceReservation, User};
use App\Services\Finance\MutationKey;
use App\Services\Records\RecordInput;
use Illuminate\Support\Facades\DB;
final class ReleaseQuickBookingCostumes
{
    public function execute(User $actor, int $eventId, array $input, string $key): array
    {
        $actor = QuickCostumeHolds::actor($actor);
        RecordWriteRejected::unless(array_diff(array_keys($input), ['expected_event_version','expected_hold_version','reason']) === [], 422, 'La solicitud contiene campos no permitidos.');
        $d = RecordInput::validate($input, ['expected_event_version' => 'required|integer|min:1', 'expected_hold_version' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:1000']);
        $d['expected_event_version'] = (int)$d['expected_event_version']; $d['expected_hold_version'] = (int)$d['expected_hold_version'];
        $hash = MutationKey::hash($d); $scope = 'owner:'.$actor->id.':event:'.$eventId.':costume-hold-release';
        return DB::transaction(function () use ($actor, $eventId, $d, $hash, $scope, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); QuickCostumeHolds::actor($actor);
            $e = Event::whereKey($eventId)->lockForUpdate()->firstOrFail(); QuickCostumeHolds::record($e);
            RecordWriteRejected::unless($e->status === 'draft' && $e->version === $d['expected_event_version'], 409, 'Solo se libera aquí un apartado de borrador vigente.');
            $h = QuickCostumeHold::where('event_id', $eventId)->lockForUpdate()->firstOrFail(); $p = QuickCostumeHolds::verify($h, $e);
            if ($receipt = MutationKey::find($scope, $key, $hash, true)) {
                $history = QuickCostumeHoldEvent::findOrFail($receipt->result_id);
                RecordWriteRejected::unless($history->quick_costume_hold_id === $h->id && $history->version === $h->version, 409, 'El apartado cambió después de esta liberación.');
                return QuickCostumeHolds::view($e);
            }
            RecordWriteRejected::unless($h->version === $d['expected_hold_version'] && in_array($h->state, ['held','review_required'], true), 409, 'El apartado cambió o ya no está activo.');
            Resource::whereIn('id', array_column($p['rows'], 'resource_id'))->orderBy('id')->lockForUpdate()->get();
            ResourceReservation::whereIn('id', array_column($p['rows'], 'reservation_id'))->where('event_id', $e->id)->delete();
            $history = QuickCostumeHolds::transition($actor, $e, $h, 'released', [], [], $d['reason']);
            MutationKey::store($scope, $key, $hash, 'quick_costume_hold_event', $history->id);
            return QuickCostumeHolds::view($e);
        }, 3);
    }
}
