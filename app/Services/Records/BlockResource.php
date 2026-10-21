<?php

namespace App\Services\Records;

use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Models\ResourceReservation;
use App\Models\User;
use App\Rules\ExplicitOffset;
use App\Services\Audit;
use App\Exceptions\RecordWriteRejected;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BlockResource
{
    public function execute(User $actor, int $resourceId, array $input): ResourceBlock
    {
        $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['starts_at' => ['required', 'date', new \App\Rules\ExplicitOffset()], 'ends_at' => ['required', 'date', 'after:starts_at', new \App\Rules\ExplicitOffset()], 'reason' => 'required|string|max:255']);
        return DB::transaction(function () use ($actor, $resourceId, $data) {
            Resource::whereKey($resourceId)->lockForUpdate()->firstOrFail();
            $start = \Carbon\CarbonImmutable::parse($data['starts_at'])->utc();
            $end = \Carbon\CarbonImmutable::parse($data['ends_at'])->utc();
            $bookings = ResourceReservation::where('resource_id', $resourceId)->with(['event' => fn ($q) => $q->sharedLock()])->orderBy('starts_at')->lockForUpdate()->get();
            RecordWriteRejected::when($bookings->contains(fn($r) => $r->event->starts_at->subMinutes($r->event->setup_minutes ?? 0)->lessThan($end) && $r->event->ends_at->addMinutes($r->event->breakdown_minutes ?? 0)->greaterThan($start)), 409, 'El bloqueo afecta un evento. Resuelve primero la asignación.');
            // A later block must also preserve driving intervals already reviewed
            // between the resource's current adjacent commitments.
            for ($i = 1; $i < $bookings->count(); $i++) {
                $from = $bookings[$i - 1]->event;
                $to = $bookings[$i]->event;
                $reviews = \App\Models\OperationalReview::whereIn('event_id', [$from->id, $to->id])->orderByDesc('id')->get();
                $trip = null;
                foreach ($reviews as $review) {
                    $trip = collect($review->evidence['transfers'] ?? [])->first(fn($route) => (int) $route['from_event_id'] === $from->id && (int) $route['to_event_id'] === $to->id);
                    if ($trip) {
                        break;
                    }
                }
                if ($trip && (int) $trip['travel_minutes'] > 0) {
                    $departure = $from->ends_at->addMinutes($from->breakdown_minutes);
                    $arrival = $departure->addMinutes((int) $trip['travel_minutes']);
                    RecordWriteRejected::when($departure->lessThan($end) && $arrival->greaterThan($start), 409, 'El bloqueo afecta un traslado comprometido. Resuelve primero la asignación.');
                }
            }
            $block = ResourceBlock::create(['resource_id' => $resourceId, 'starts_at' => $start, 'ends_at' => $end, 'reason' => $data['reason']]);
            Audit::recordFor($actor, 'resource.blocked', $block, [], $block->getAttributes());
            return $block->fresh();
        });
    }
}
