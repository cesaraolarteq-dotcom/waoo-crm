<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventGoogleLink extends Model
{
    protected $fillable = ['event_id', 'google_event_id', 'synced_hash', 'synced_at', 'last_error'];
    protected function casts(): array { return ['synced_at' => 'immutable_datetime']; }
}
