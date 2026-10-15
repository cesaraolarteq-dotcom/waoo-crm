<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResourceBlock extends Model
{
    protected $fillable = ['resource_id', 'starts_at', 'ends_at', 'reason'];
    protected function casts(): array { return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime']; }
}
