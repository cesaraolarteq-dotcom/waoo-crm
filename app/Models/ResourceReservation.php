<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResourceReservation extends Model
{
    protected $fillable = ['event_id', 'resource_id', 'starts_at', 'ends_at'];
    protected function casts(): array { return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime']; }

    public function event() { return $this->belongsTo(Event::class); }
}
