<?php
namespace App\Services;

use App\Models\{Character, Event, Resource, ResourceBlock, ResourceReservation};
use App\Services\Availability\WebsiteCapacity;

/** Read-only form defaults, never an availability promise or a new hold. Confirmation revalidates under its locks. */
final class SuggestEventCostumes
{
    public static function for(Event $event): array
    {
        $empty = ['ids' => [], 'wardrobe' => []]; $slots = [];
        foreach ($event->services()->get() as $service) foreach ((array) $service->requirements_snapshot as $req) {
            if (($req['kind'] ?? null) !== 'costume' || empty($req['character_id'])) continue;
            $quantity = (int) ($req['quantity'] ?? 1);
            if ($quantity < 1 || $quantity > 100 || count($slots) + $quantity > 100) return $empty;
            for ($i = 0; $i < $quantity; $i++) $slots[] = $req;
        }
        if ($slots === []) return $empty;
        $characters = Character::whereIn('id', array_column($slots, 'character_id'))->get()->keyBy('id');
        $units = Resource::where('kind', 'costume')->where('status', 'active')->whereIn('character_id', $characters->keys())->orderBy('name')->limit(257)->get();
        if ($units->count() > 256) return $empty;
        // Unknown setup/breakdown is a conservative suggestion window, never proof of a route.
        $start = $event->starts_at->subMinutes($event->setup_minutes ?? WebsiteCapacity::UNKNOWN_MINUTES);
        $end = $event->ends_at->addMinutes(($event->breakdown_minutes ?? WebsiteCapacity::UNKNOWN_MINUTES) + WebsiteCapacity::GAP_MINUTES);
        $busy = ResourceBlock::whereIn('resource_id', $units->pluck('id'))->whereNull('released_at')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->pluck('resource_id')->flip()->all();
        $reservations = ResourceReservation::whereIn('resource_id', $units->pluck('id'))->with('event')->orderBy('id')->limit(10001)->get();
        if ($reservations->count() > 10000) return $empty;
        $own = [];
        foreach ($reservations as $r) {
            if ($r->event_id === $event->id) { $own[$r->resource_id] = true; continue; }
            $neighbor = $r->event;
            if (! $neighbor) { $busy[$r->resource_id] = true; continue; }
            $from = $neighbor->starts_at->subMinutes($neighbor->setup_minutes ?? WebsiteCapacity::UNKNOWN_MINUTES)->min($r->starts_at);
            $to = $neighbor->ends_at->addMinutes(($neighbor->breakdown_minutes ?? WebsiteCapacity::UNKNOWN_MINUTES) + WebsiteCapacity::GAP_MINUTES)->max($r->ends_at);
            if ($from->lessThan($end) && $to->greaterThan($start)) $busy[$r->resource_id] = true;
        }
        // Existing held units first; augmenting matching still lets roles move instead of consuming a special outfit greedily.
        $units = $units->reject(fn ($u) => isset($busy[$u->id]))->sortBy(fn ($u) => isset($own[$u->id]) ? 0 : 1)->values();
        $assignment = app(MatchEventResources::class)->partial($slots, $units->all(), fn ($slot, $unit) => CostumeOutfits::fits($slot, $unit, $characters, []));
        $ids = []; $wardrobe = [];
        foreach ($assignment as $index => $slotIndex) {
            $unit = $units[$index]; $ids[] = (int) $unit->id; $outfit = $slots[$slotIndex]['outfit'] ?? null;
            if ($unit->outfit === null && $outfit !== null) $wardrobe[] = ['resource_id' => (int) $unit->id, 'outfit' => $outfit,
                'expected_character_version' => (int) $characters[$unit->character_id]->version];
        }
        return ['ids' => $ids, 'wardrobe' => $wardrobe];
    }
}
