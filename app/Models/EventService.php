<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventService extends Model
{
    protected $fillable = ['event_id', 'service_variant_id', 'price_version_id', 'requirements_snapshot', 'duration_minutes', 'operational_ready', 'extra_characters', 'extra_character_cents'];
    protected function casts(): array { return ['requirements_snapshot' => 'array', 'operational_ready' => 'boolean', 'extra_characters' => 'integer', 'extra_character_cents' => 'integer']; }

    public function characters() { return $this->hasMany(EventServiceCharacter::class); }

    /** Add-on for characters beyond the package's included ones, at the per-character amount frozen on the service. */
    public static function extraCents(self $service): int
    {
        return (int) $service->extra_characters * (int) $service->extra_character_cents;
    }

    /** Extra fields on a price line only when there are extra characters, so existing snapshots keep their exact shape. */
    public static function extraSnapshot(self $service, int $baseCents): array
    {
        return (int) $service->extra_characters > 0 ? ['base_amount_cents' => $baseCents, 'extra_characters' => (int) $service->extra_characters,
            'extra_character_cents' => (int) $service->extra_character_cents] : [];
    }
}
