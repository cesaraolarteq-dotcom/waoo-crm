<?php
namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, EventDiscount, PriceVersion, User};
use App\Services\Audit;

/**
 * Owner 2026-11-20: in Reserva rápida the owner may give "$100 off" or "10 % off". It applies to the packages and add-ons
 * (extra characters), never to the travel fee, and never goes below $0. Stored once per event and kept in every revision.
 */
final class OwnerDiscount
{
    public const RULES = ['discount' => 'sometimes|array:kind,value', 'discount.kind' => 'required_with:discount|in:fixed,percent',
        'discount.value' => 'required_with:discount|integer|min:1|max:100000000'];

    public static function cents(string $kind, int $value, int $baseCents): int
    {
        return max(0, min($baseCents, $kind === 'percent' ? intdiv($baseCents * min($value, 100) + 50, 100) : $value));
    }

    /** Caller holds the event lock, inside the booking transaction. */
    public static function apply(User $actor, Event $event, array $discount): EventDiscount
    {
        RecordWriteRejected::unless($discount['kind'] !== 'percent' || (int) $discount['value'] <= 100, 422, 'El descuento en porcentaje va de 1 a 100.');
        RecordWriteRejected::unless(! EventDiscount::where('event_id', $event->id)->exists(), 409, 'Esta reserva ya tiene un descuento.');
        $row = EventDiscount::create(['event_id' => $event->id, 'kind' => $discount['kind'], 'value' => (int) $discount['value'], 'created_by' => $actor->id]);
        Audit::recordFor($actor, 'booking.discount_given', $row, [], ['event_id' => $event->id, 'kind' => $row->kind, 'value' => $row->value]);
        return $row;
    }

    /** @param iterable<\App\Models\EventService> $services @return array{cents:int,snapshot:array}|null */
    public static function forEvent(Event $event, iterable $services, int $alreadyDiscounted = 0): ?array
    {
        $row = EventDiscount::where('event_id', $event->id)->first();
        if (! $row) return null;
        $base = 0;
        foreach ($services as $s) $base += (int) PriceVersion::whereKey($s->price_version_id)->value('amount_cents') + \App\Models\EventService::extraCents($s);
        $cents = self::cents($row->kind, $row->value, max(0, $base - $alreadyDiscounted));
        if ($cents === 0) return null;
        return ['cents' => $cents, 'snapshot' => [['kind' => 'owner', 'label' => $row->kind === 'percent' ? "Descuento {$row->value} % / {$row->value}% off" : 'Descuento / Discount',
            'type' => $row->kind, 'value' => $row->value, 'applies_to' => 'services_not_travel', 'base_cents' => $base, 'amount_cents' => $cents, 'event_discount_id' => $row->id]]];
    }
}
