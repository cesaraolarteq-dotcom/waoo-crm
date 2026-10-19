<?php
namespace App\Services\Responsibility;

use App\Exceptions\ResponsibilityRejected;
use App\Models\Event;

final class RequireCrmResponsibility
{
    // Callers preserve grant -> event locks. No transfer/toggle is exposed by C1.
    public static function forEvent(int $eventId, bool $lock = false): Event
    {
        $query = Event::whereKey($eventId);
        $event = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        self::check($event);
        return $event;
    }

    public static function check(Event $event): void
    {
        if ($event->responsible_system !== 'crm') throw new ResponsibilityRejected();
    }
}
