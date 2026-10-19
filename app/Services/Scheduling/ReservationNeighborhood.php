<?php

namespace App\Services\Scheduling;

use App\Models\Event;

/** @internal Caller holds the event and sorted resource row locks; this is not an availability promise. */
final class ReservationNeighborhood
{
    /** @return array{overlaps: bool, before: ?Event, after: ?Event} */
    public function forResource(Event $candidate, int $resourceId): array
    {
        $query = Event::query()
            ->join('resource_reservations as calendar', 'calendar.event_id', '=', 'events.id')
            ->where('calendar.resource_id', $resourceId)
            ->select('events.*');
        [$start, $end] = match ($query->getConnection()->getDriverName()) {
            'mysql' => ['DATE_SUB(events.starts_at, INTERVAL COALESCE(events.setup_minutes, 0) MINUTE)',
                'DATE_ADD(events.ends_at, INTERVAL COALESCE(events.breakdown_minutes, 0) MINUTE)'],
            'sqlite' => ["datetime(events.starts_at, '-' || COALESCE(events.setup_minutes, 0) || ' minutes')",
                "datetime(events.ends_at, '+' || COALESCE(events.breakdown_minutes, 0) || ' minutes')"],
            default => throw new \LogicException('Reservation queries require the supported MariaDB or SQLite connection.'),
        };
        $overlaps = (clone $query)
            ->whereRaw($start.' < ?', [$candidate->ends_at->addMinutes($candidate->breakdown_minutes)->utc()])
            ->whereRaw($end.' > ?', [$candidate->starts_at->subMinutes($candidate->setup_minutes)->utc()])
            ->exists();
        if ($overlaps) {
            return ['overlaps' => true, 'before' => null, 'after' => null];
        }

        // Keep the prior scan's reservation ordering and query all dates; no fixed window drops a long event or distant neighbor.
        $before = (clone $query)->where('events.ends_at', '<=', $candidate->starts_at->utc())
            ->orderByDesc('calendar.starts_at')->first();
        $after = (clone $query)->where('events.starts_at', '>=', $candidate->ends_at->utc())
            ->orderBy('calendar.starts_at')->first();

        return compact('overlaps', 'before', 'after');
    }
}
