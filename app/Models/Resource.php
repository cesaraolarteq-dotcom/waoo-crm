<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    protected $fillable = ['code', 'name', 'kind', 'status', 'character_id', 'outfit', 'location', 'skills', 'equipment_kind', 'seats'];
    protected function casts(): array { return ['skills' => 'array', 'version' => 'integer', 'seats' => 'integer']; }
}
