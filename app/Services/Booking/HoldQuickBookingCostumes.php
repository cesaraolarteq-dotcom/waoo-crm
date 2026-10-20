<?php
namespace App\Services\Booking;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Character, Event, QuickCostumeHold, QuickCostumeHoldEvent, Resource, ResourceReservation, User};
use App\Services\{CostumeOutfits, MatchEventResources};
use App\Services\Finance\MutationKey;
use App\Services\Records\RecordInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
final class HoldQuickBookingCostumes
{
    public function execute(User $actor, int $eventId, array $input, string $key): array
    {
        $actor = QuickCostumeHolds::actor($actor);
        RecordWriteRejected::unless(array_diff(array_keys($input), ['expected_event_version','expected_hold_version','characters']) === [], 422, 'La solicitud contiene campos no permitidos.');
        $d = RecordInput::validate($input, ['expected_event_version' => 'required|integer|min:1', 'expected_hold_version' => 'required|integer|min:0',
            'characters' => 'present|array|list|max:100', 'characters.*' => 'array:id,version',
            'characters.*.id' => 'required|integer|min:1|distinct', 'characters.*.version' => 'required|integer|min:1']);
        $d['expected_event_version'] = (int)$d['expected_event_version']; $d['expected_hold_version'] = (int)$d['expected_hold_version'];
        $d['characters'] = collect($d['characters'])->map(fn ($c) => ['id' => (int)$c['id'], 'version' => (int)$c['version']])->sortBy('id')->values()->all();
        $hash = MutationKey::hash($d); $scope = 'owner:'.$actor->id.':event:'.$eventId.':costume-hold';
        return DB::transaction(function () use ($actor, $eventId, $d, $hash, $scope, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); QuickCostumeHolds::actor($actor);
            $e = Event::whereKey($eventId)->lockForUpdate()->firstOrFail(); $q = QuickCostumeHolds::record($e);
            RecordWriteRejected::unless($e->status === 'draft' && $e->version === $d['expected_event_version'] && !$e->starts_at->isPast(), 409, 'La fiesta cambió o ya empezó. Recarga antes de apartar.');
            $h = QuickCostumeHold::where('event_id', $eventId)->lockForUpdate()->first();
            if ($receipt = MutationKey::find($scope, $key, $hash, true)) {
                $history = QuickCostumeHoldEvent::findOrFail($receipt->result_id);
                RecordWriteRejected::unless($h && $history->quick_costume_hold_id === $h->id && $history->version === $h->version, 409, 'El apartado cambió después de este intento.');
                QuickCostumeHolds::verify($h, $e); return QuickCostumeHolds::view($e);
            }
            RecordWriteRejected::unless(($h?->version ?? 0) === $d['expected_hold_version'], 409, 'El apartado cambió. Recarga la ficha.');
            if ($h) QuickCostumeHolds::verify($h, $e);
            $compiled = QuickCostumeHolds::slots($e); $slots = $compiled['slots'];
            $characters = Character::whereIn('id', collect($slots)->pluck('character_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            RecordWriteRejected::unless($characters->values()->map(fn ($c) => ['id' => $c->id, 'version' => $c->version])->all() === $d['characters'], 409, 'El personaje cambió. Revisa sus vestuarios.');
            if ($h && in_array($h->state, ['held','review_required'], true)) {
                $history = QuickCostumeHoldEvent::where('quick_costume_hold_id', $h->id)->where('version', $h->version)->firstOrFail();
                MutationKey::store($scope, $key, $hash, 'quick_costume_hold_event', $history->id); return QuickCostumeHolds::view($e);
            }
            $h ??= QuickCostumeHold::create(['event_id' => $e->id, 'quick_booking_id' => $q->id, 'receipt_id' => $q->receipt_id,
                'version' => 0, 'state' => 'not_attempted', 'payload' => [], 'payload_sha256' => str_repeat('0', 64)]);
            $rows = []; $issues = []; $state = $compiled['missing'] ? 'needs_character' : ($slots === [] ? 'not_required' : 'held');
            if ($state === 'held') {
                $units = Resource::where('kind', 'costume')->where('status', 'active')->whereIn('character_id', $characters->keys())->orderBy('id')->limit(501)->lockForUpdate()->get();
                RecordWriteRejected::unless($units->count() <= 500, 409, 'El apartado requiere revisión por volumen.');
                $available = $units->filter(fn ($unit) => QuickCostumeHolds::available($e, $unit))->values();
                try {
                    $assignment = app(MatchEventResources::class)->execute($slots, $available->all(), fn ($slot, $unit) => $characters->get($unit->character_id)?->active && CostumeOutfits::fits($slot, $unit, $characters, []));
                    foreach ($assignment as $resourceIndex => $slotIndex) {
                        $unit = $available[$resourceIndex]; $slot = $slots[$slotIndex];
                        $reservation = ResourceReservation::create(['event_id' => $e->id, 'resource_id' => $unit->id, 'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at]);
                        $rows[] = ['resource_id' => $unit->id, 'resource_version' => $unit->version, 'character_id' => $unit->character_id,
                            'character_version' => $characters[$unit->character_id]->version, 'character_name' => $characters[$unit->character_id]->name,
                            'outfit' => $unit->outfit ?? ($slot['outfit'] ?? null), 'reservation_id' => $reservation->id];
                    }
                    if ($e->setup_minutes === null || $e->breakdown_minutes === null) { $state = 'review_required'; $issues[] = 'timing_unknown'; }
                } catch (ValidationException) { $state = 'blocked'; $issues[] = 'costume_unavailable'; }
            }
            if ($state === 'needs_character') $issues[] = 'needs_character';
            $history = QuickCostumeHolds::transition($actor, $e, $h, $state, $rows, $issues);
            MutationKey::store($scope, $key, $hash, 'quick_costume_hold_event', $history->id);
            return QuickCostumeHolds::view($e);
        }, 3);
    }
}
