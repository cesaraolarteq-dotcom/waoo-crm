<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceVariant extends Model
{
    protected $fillable = ['name', 'status', 'duration_minutes', 'requirements'];
    protected function casts(): array { return ['requirements' => 'array', 'duration_minutes' => 'integer', 'version' => 'integer']; }
}
