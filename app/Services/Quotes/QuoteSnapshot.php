<?php
namespace App\Services\Quotes;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer, Event, PriceVersion, ServiceVariant, User};
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class QuoteSnapshot
{
    public static function capture(Event $event, User $actor, array $data): array
    {
        RecordWriteRejected::unless($event->status === 'draft' && $event->version === $data['expected_event_version'], 409, 'El borrador cambió. Recarga antes de cotizar.');
        $validUntil = isset($data['valid_until']) ? CarbonImmutable::parse($data['valid_until'])->utc() : CarbonImmutable::now()->addDays(7);
        if (!$validUntil->isFuture() || $validUntil->greaterThan($event->starts_at)) throw ValidationException::withMessages(['valid_until' => 'La vigencia debe ser futura y no posterior al comienzo del evento.']);
        $customer = Customer::findOrFail($event->customer_id);
        $services = $event->services()->with('characters')->get(); $lines = []; $subtotal = 0;
        if ($services->isEmpty() || $event->travel_fee_cents === null) throw ValidationException::withMessages(['event_id' => 'Completa servicios y costo de transporte; no se asume cero.']);
        foreach ($services as $service) {
            $price = PriceVersion::findOrFail($service->price_version_id);
            $variant = ServiceVariant::findOrFail($service->service_variant_id);
            if ($price->currency !== 'USD' || $variant->status !== 'active' || !$service->operational_ready) throw ValidationException::withMessages(['event_id' => 'Selecciona servicios activos en USD y personajes completos antes de cotizar.']);
            $characters = $service->characters->map(fn ($c) => ['character_id' => $c->character_id, 'quantity' => (int) $c->quantity,
                'name' => $c->character_name_snapshot, ...($c->outfit !== null ? ['outfit' => $c->outfit] : [])])->all();
            if ($characters === []) foreach ($service->requirements_snapshot as $r) if ($r['kind'] === 'costume') {
                $characters[] = ['character_id' => $r['character_id'], 'quantity' => (int) $r['quantity'],
                    'name' => \App\Models\Character::findOrFail($r['character_id'])->name, ...(isset($r['outfit']) ? ['outfit' => $r['outfit']] : [])];
            }
            $lines[] = ['price_version_id' => $price->id, 'service_variant_id' => $variant->id, 'name' => $variant->name,
                'long_description' => $variant->long_description, 'duration_minutes' => $service->duration_minutes,
                'quantity' => 1, 'amount_cents' => $price->amount_cents, 'characters' => $characters, 'requirements_snapshot' => $service->requirements_snapshot];
            $subtotal += $price->amount_cents;
        }
        $discounts = []; $discountTotal = 0;
        foreach ($data['discounts'] as $index => $discount) {
            $expiry = isset($discount['expires_at']) ? CarbonImmutable::parse($discount['expires_at'])->utc() : null;
            if ($expiry && $expiry->lessThan($validUntil)) throw ValidationException::withMessages(["discounts.{$index}.expires_at" => 'El descuento debe cubrir toda la vigencia de la cotización.']);
            $discounts[] = [...$discount, 'expires_at' => $expiry?->toIso8601String(), 'approved_by' => $actor->id, 'approved_at' => now()->toIso8601String()];
            $discountTotal += $discount['amount_cents'];
        }
        $total = $subtotal + $event->travel_fee_cents - $discountTotal;
        if ($total < 1) throw ValidationException::withMessages(['discounts' => 'El total de la cotización debe ser mayor que cero.']);
        return ['event_version' => $event->version, 'currency' => 'USD',
            'customer_snapshot' => $customer->only(['id','name','email','phone','locale']),
            'event_snapshot' => ['starts_at' => $event->starts_at->toIso8601String(), 'ends_at' => $event->ends_at->toIso8601String(),
                'timezone' => $event->timezone, 'address' => $event->address, 'postal_code' => $event->postal_code],
            'lines_snapshot' => $lines, 'travel_fee_evidence' => $event->travel_fee_evidence, 'discounts_snapshot' => $discounts,
            'subtotal_cents' => $subtotal, 'travel_fee_cents' => $event->travel_fee_cents, 'discount_cents' => $discountTotal,
            'total_cents' => $total, 'deposit_target_cents' => intdiv($total, 2) + ($total % 2), 'valid_until' => $validUntil, 'created_by' => $actor->id];
    }
}
