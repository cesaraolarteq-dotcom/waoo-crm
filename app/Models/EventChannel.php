<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** How the client found Waoo for one party (owner 2026-10-28). One row per event; the owner can correct it. */
class EventChannel extends Model
{
    public const LABELS = ['instagram' => 'Instagram', 'whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'website' => 'Página web', 'other' => 'Otro'];

    protected $guarded = ['id'];

    /** Saves the owner's choice; an empty choice leaves any earlier answer as it was. */
    public static function record(int $eventId, ?string $channel, ?int $userId): void
    {
        if (! isset(self::LABELS[(string) $channel])) return;
        self::updateOrCreate(['event_id' => $eventId], ['channel' => $channel, 'set_by' => $userId]);
    }
}
