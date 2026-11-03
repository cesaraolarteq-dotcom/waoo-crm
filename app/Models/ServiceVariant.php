<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceVariant extends Model
{
    protected $fillable = ['name', 'long_description', 'status', 'duration_minutes', 'setup_minutes', 'breakdown_minutes', 'requirements', 'character_quantity', 'character_options', 'extra_character_cents'];
    protected function casts(): array { return ['requirements' => 'array', 'character_options' => 'array', 'character_quantity' => 'integer', 'duration_minutes' => 'integer', 'version' => 'integer', 'setup_minutes' => 'integer', 'breakdown_minutes' => 'integer', 'extra_character_cents' => 'integer']; }

    /** Price of each character beyond the included ones: this package's own, or the general one. */
    public static function extraCharacterPrice(?self $variant): int
    {
        return (int) ($variant?->extra_character_cents ?? config('waoo.extra_character_cents'));
    }
}
