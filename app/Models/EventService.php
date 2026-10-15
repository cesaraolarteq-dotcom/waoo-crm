<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventService extends Model
{
    protected $fillable = ['event_id', 'service_variant_id', 'price_version_id', 'requirements_snapshot', 'duration_minutes', 'operational_ready'];
    protected function casts(): array { return ['requirements_snapshot' => 'array', 'operational_ready' => 'boolean']; }
}
