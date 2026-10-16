<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceVariant extends Model
{
    protected $fillable = ['name', 'status', 'duration_minutes', 'requirements', 'character_quantity', 'character_options'];
    protected function casts(): array { return ['requirements' => 'array', 'character_options' => 'array', 'character_quantity' => 'integer', 'duration_minutes' => 'integer', 'version' => 'integer']; }
}
