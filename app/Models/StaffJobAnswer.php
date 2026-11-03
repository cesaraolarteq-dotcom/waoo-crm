<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffJobAnswer extends Model
{
    protected $guarded = [];

    protected $casts = ['event_starts_at' => 'immutable_datetime', 'answered_at' => 'immutable_datetime'];

    /** Answer still about this party at its current time, or null. */
    public static function current(Event $event, int $resourceId): ?self
    {
        $a = self::where('event_id', $event->id)->where('resource_id', $resourceId)->first();

        return $a && $a->event_starts_at->equalTo($event->starts_at) ? $a : null;
    }
}
