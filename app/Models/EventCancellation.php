<?php
namespace App\Models;

class EventCancellation extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['released_reservations' => 'integer']; }
}
