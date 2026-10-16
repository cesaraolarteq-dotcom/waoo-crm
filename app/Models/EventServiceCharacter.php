<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventServiceCharacter extends Model
{
    protected $fillable = ['event_service_id', 'character_id', 'quantity', 'character_name_snapshot'];

    protected function casts(): array
    {
        return ['character_id' => 'integer', 'quantity' => 'integer'];
    }
}
