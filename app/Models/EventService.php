<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventService extends Model
{
    protected $fillable = ['event_id', 'service_variant_id', 'price_version_id', 'requirements_snapshot', 'duration_minutes', 'operational_ready', 'extra_characters', 'extra_character_cents', 'extends_event_snapshot', 'covers_event_snapshot'];
    protected function casts(): array { return ['requirements_snapshot' => 'array', 'operational_ready' => 'boolean', 'extra_characters' => 'integer', 'extra_character_cents' => 'integer', 'extends_event_snapshot' => 'boolean', 'covers_event_snapshot' => 'boolean']; }

    /** Only frozen service evidence controls an existing party; catalog changes apply to future selections. */
    public static function partyMinutes(iterable $services): int
    {
        $base=0;$extra=0;
        foreach($services as $s) { if($s->extends_event_snapshot)$extra+=(int)$s->duration_minutes;elseif(!$s->covers_event_snapshot)$base=max($base,(int)$s->duration_minutes); }
        return $base+$extra;
    }

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
