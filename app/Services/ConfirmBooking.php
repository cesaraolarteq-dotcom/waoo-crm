<?php

namespace App\Services;

use App\Models\{BookingEvidence, Event, OperationalReview, Resource, ResourceBlock, ResourceReservation};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
final class ConfirmBooking
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['booking' => $message]);
    }
    public function execute(int $eventId, array $data, ?\App\Models\User $actor = null): Event
    {
        $actor ??= auth()->user();
        if (! $actor) throw new \Illuminate\Auth\Access\AuthorizationException('Acceso exclusivo de administración.');
        $actor = \App\Services\Records\RecordActor::authorize($actor);
        try {
            return DB::transaction(function () use ($eventId, $data, $actor) {
                $event = Event::query()->lockForUpdate()->findOrFail($eventId);
                $ids = collect($data['resource_ids'])->map(fn($id) => (int) $id)->unique()->sort()->values();
                if ($event->status === 'capacity_reserved') {
                    $existing = BookingEvidence::where('event_id', $event->id)->firstOrFail();
                    $same = $event->reservations()->orderBy('resource_id')->pluck('resource_id')->all() === $ids->all();
                    abort_unless($same && $existing->method === $data['payment']['method'] && $existing->received_at->equalTo(CarbonImmutable::parse($data['payment']['received_at'])) && OperationalReview::where('event_id', $event->id)->firstOrFail()->evidence == $data['review'] && $existing->reference === $data['payment']['reference'] && $existing->amount_cents === (int) $data['payment']['amount_cents'], 409, 'La reserva ya está confirmada con otros datos.');
                    return $event;
                }
                abort_unless($event->status === 'draft' && $event->version === (int) $data['expected_version'], 409, 'El evento cambió. Actualiza la ficha.');
                if (!$event->address || $event->setup_minutes === null || $event->breakdown_minutes === null) {
                    $this->fail('Completa dirección, montaje y desmontaje.');
                }
                if ($event->starts_at->isPast()) {
                    $this->fail('No se puede reservar un evento pasado.');
                }
                // Conservative Phase 1: approve a short-notice event manually; no automatic offers.
                if ($event->starts_at->lessThan(now()->addDays(7)) && empty($data['review']['short_notice_reason'])) {
                    $this->fail('Requiere aprobación por proximidad de fecha.');
                }
                $resources = Resource::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                if ($resources->count() !== $ids->count() || $resources->contains(fn($r) => $r->status !== 'active')) {
                    $this->fail('Hay recursos inexistentes o no disponibles.');
                }
                $services = $event->services()->get();
                if ($services->isEmpty()) {
                    $this->fail('Selecciona servicios con requisitos completos.');
                }
                if ($event->travel_fee_cents === null) {
                    $this->fail('Revisa el costo de transporte; no se asume cero.');
                }
                $total = \App\Models\PriceVersion::whereIn('id', $services->pluck('price_version_id'))->sum('amount_cents') + $event->travel_fee_cents;
                if ((int) $data['payment']['amount_cents'] * 2 < $total && empty($data['review']['deposit_exception_reason'])) {
                    $this->fail('El depósito no alcanza el objetivo. Registra una excepción aprobada o completa el pago.');
                }
                $slots = [];
                foreach ($services as $service) {
                    if (!$service->operational_ready || empty($service->requirements_snapshot)) {
                        $this->fail('Faltan requisitos operativos.');
                    }
                    if ($event->starts_at->diffInMinutes($event->ends_at) < $service->duration_minutes) {
                        $this->fail('La duración del evento no cubre el servicio.');
                    }
                    foreach ($service->requirements_snapshot as $req) {
                        for ($i = 0; $i < $req['quantity']; $i++) {
                            $slots[] = $req;
                        }
                    }
                }
                foreach (collect($slots)->where('kind', 'costume')->filter(fn ($s) => isset($s['outfit']))->groupBy(fn ($s) => json_encode([(int) $s['character_id'], $s['outfit']])) as $requested) {
                    $slot = $requested->first();
                    $matching = $resources->filter(fn ($r) => $r->kind === 'costume' && (int) $r->character_id === (int) $slot['character_id'] && $r->outfit === $slot['outfit']);
                    if ($matching->count() < $requested->count()) {
                        $name = \App\Models\Character::whereKey($slot['character_id'])->value('name');
                        throw new \App\Exceptions\RecordWriteRejected(409, "Selecciona suficientes trajes {$name} ({$slot['outfit']}); no se sustituye la versión.");
                    }
                }
                // Merge costume-wearer constraints into performer roles before matching.
                // This solves all simultaneous staff roles together instead of choosing
                // a face painter first and rejecting an otherwise valid crew pairing.
                $costumes = $resources->where('kind', 'costume')->values();
                $compatibilities = DB::table('costume_compatibilities')->whereIn('costume_id', $costumes->pluck('id'))->get();
                foreach ($costumes as $costume) {
                    $performerSlot = null;
                    foreach ($slots as $index => $slot) {
                        if ($slot['kind'] === 'staff' && ($slot['skill'] ?? null) === 'performer' && !isset($slot['costume_unit_id'])) {
                            $performerSlot = $index;
                            break;
                        }
                    }
                    if ($performerSlot === null) {
                        $slots[] = ['kind' => 'staff', 'skill' => 'performer', 'costume_unit_id' => $costume->id];
                    } else {
                        $slots[$performerSlot]['costume_unit_id'] = $costume->id;
                    }
                }
                $this->match($slots, $resources->all(), function ($slot, $r) use ($compatibilities) {
                    return $r->kind === $slot['kind'] && (!isset($slot['outfit']) || $r->outfit === $slot['outfit']) && (empty($slot['skill']) || in_array($slot['skill'], $r->skills ?? [], true)) && (empty($slot['character_id']) || (int) $r->character_id === (int) $slot['character_id']) && (empty($slot['equipment_kind']) || $r->equipment_kind === $slot['equipment_kind']) && (empty($slot['costume_unit_id']) || $compatibilities->contains(fn($c) => (int) $c->staff_id === $r->id && (int) $c->costume_id === $slot['costume_unit_id']));
                }, 'No hay una combinación de recursos e intérpretes compatibles que cubra todos los roles simultáneos.');
                $vehicles = $resources->where('kind', 'vehicle');
                if ($vehicles->isNotEmpty()) {
                    if ($vehicles->contains(fn($v) => !$v->seats) || $vehicles->sum('seats') < $resources->where('kind', 'staff')->count()) {
                        $this->fail('La capacidad de pasajeros no alcanza o está incompleta.');
                    }
                    if ($resources->where('kind', 'staff')->filter(fn($r) => in_array('driver', $r->skills ?? [], true))->count() < $vehicles->count()) {
                        $this->fail('Faltan conductores para los vehículos seleccionados.');
                    }
                }
                $busyStart = $event->starts_at->subMinutes($event->setup_minutes);
                $busyEnd = $event->ends_at->addMinutes($event->breakdown_minutes);
                foreach ($resources->sortBy(fn ($r) => $r->kind === 'costume' ? 0 : 1) as $resource) {
                    if (ResourceBlock::where('resource_id', $resource->id)->whereNull('released_at')->where('starts_at', '<', $busyEnd)->where('ends_at', '>', $busyStart)->exists()) {
                        $this->rejectVersionConflict($resource, $slots);
                        $this->fail("El recurso {$resource->code} tiene un bloqueo horario.");
                    }
                    $bookings = ResourceReservation::where('resource_id', $resource->id)->with('event')->orderBy('starts_at')->get();
                    $before = null;
                    $after = null;
                    foreach ($bookings as $booking) {
                        $other = $booking->event;
                        if ($other->starts_at->subMinutes($other->setup_minutes)->lessThan($busyEnd) && $other->ends_at->addMinutes($other->breakdown_minutes)->greaterThan($busyStart)) {
                            $this->rejectVersionConflict($resource, $slots);
                            $this->fail("Conflicto de horario: {$resource->code}.");
                        }
                        if ($other->ends_at->lessThanOrEqualTo($event->starts_at)) {
                            $before = $other;
                        }
                        if ($after === null && $other->starts_at->greaterThanOrEqualTo($event->ends_at)) {
                            $after = $other;
                        }
                    }
                    if ($before) {
                        $this->checkTransfer($before, $event, $data['review'], $resource->id);
                    }
                    if ($after) {
                        $this->checkTransfer($event, $after, $data['review'], $resource->id);
                    }
                }
                if (BookingEvidence::where('reference', $data['payment']['reference'])->exists()) {
                    $this->fail('La referencia de pago ya está asociada a otro evento.');
                }
                BookingEvidence::create([...$data['payment'], 'event_id' => $event->id, 'verified_by' => $actor->id]);
                foreach ($resources as $resource) {
                    ResourceReservation::create(['event_id' => $event->id, 'resource_id' => $resource->id, 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at]);
                }
                OperationalReview::create(['event_id' => $event->id, 'reviewed_by' => $actor->id, 'evidence' => $data['review']]);
                $event->status = 'capacity_reserved';
                // Contract/payment lifecycle remains separate for Phase 4.
                $event->version++;
                $event->save();
                Audit::recordFor($actor, 'booking.capacity_reserved', $event, ['status' => 'draft'], ['resource_ids' => $ids->all(), 'version' => $event->version, 'booking_evidence_id' => BookingEvidence::where('event_id', $event->id)->value('id')]);
                return $event;
            }, 3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $this->fail('La referencia o asignación ya fue registrada. Actualiza y revisa el evento.');
        }
    }
    private function rejectVersionConflict(Resource $resource, array $slots): void
    {
        if ($resource->kind !== 'costume') return;
        $versioned = collect($slots)->contains(fn ($slot) => $slot['kind'] === 'costume' && isset($slot['outfit']) && (int) ($slot['character_id'] ?? 0) === (int) $resource->character_id && $slot['outfit'] === $resource->outfit);
        if ($versioned) {
            $name = \App\Models\Character::whereKey($resource->character_id)->value('name');
            throw new \App\Exceptions\RecordWriteRejected(409, "No hay traje {$name} ({$resource->outfit}) libre a esa hora.");
        }
    }
    private function checkTransfer(Event $from, Event $to, array $review, int $resourceId): void
    {
        $route = collect($review['transfers'] ?? [])->first(fn($r) => (int) $r['from_event_id'] === $from->id && (int) $r['to_event_id'] === $to->id);
        if (!$route || (int) $route['from_version'] !== $from->version || (int) $route['to_version'] !== $to->version) {
            $this->fail('Falta revisión vigente del traslado con un evento vecino.');
        }
        $departure = $from->ends_at->addMinutes($from->breakdown_minutes);
        if (!CarbonImmutable::parse($route['departure_at'])->equalTo($departure)) {
            $this->fail('La ruta debe corresponder a la salida después de desmontar.');
        }
        $arrival = $departure->addMinutes((int) $route['travel_minutes']);
        if ($arrival->greaterThan($departure) && ResourceBlock::where('resource_id', $resourceId)->whereNull('released_at')->where('starts_at', '<', $arrival)->where('ends_at', '>', $departure)->exists()) {
            $this->fail('Un bloqueo del recurso impide el traslado entre eventos.');
        }
        // This backend conservatively uses 45 minutes; reduced-buffer exceptions require the later approval workflow.
        $extra = max(45, $from->breakdown_minutes + $to->setup_minutes + (int) $route['contingency_minutes']);
        if ($from->ends_at->addMinutes((int) $route['travel_minutes'] + $extra)->greaterThan($to->starts_at)) {
            $this->fail('No cabe viaje, montaje y margen con el evento vecino.');
        }
    }
    private function match(array $slots, array $resources, callable $fits, string $message): array
    {
        if (count($slots) > count($resources)) {
            $this->fail($message);
        }
        $assigned = [];
        $search = function (int $slot, array &$seen) use (&$search, &$assigned, $slots, $resources, $fits): bool {
            foreach ($resources as $i => $resource) {
                if (isset($seen[$i]) || !$fits($slots[$slot], $resource)) {
                    continue;
                }
                $seen[$i] = true;
                if (!isset($assigned[$i]) || $search($assigned[$i], $seen)) {
                    $assigned[$i] = $slot;
                    return true;
                }
            }
            return false;
        };
        foreach (array_keys($slots) as $i) {
            $seen = [];
            if (!$search($i, $seen)) {
                $this->fail($message);
            }
        }
        return $assigned;
    }
}
