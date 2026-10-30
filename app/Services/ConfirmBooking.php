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
        $data['costume_outfits'] = CostumeOutfits::normalize($data['costume_outfits'] ?? []);
        try {
            return DB::transaction(function () use ($eventId, $data, $actor) {
            \App\Services\Scheduling\DailyCapacity::lock();
                $event = Event::query()->lockForUpdate()->findOrFail($eventId);
                \App\Services\Booking\QuickStripeLeases::assertNoActive($eventId);
                $ids = collect($data['resource_ids'])->map(fn($id) => (int) $id)->unique()->sort()->values();
                $hold = \App\Services\Booking\QuickCostumeHolds::confirmation($event, $ids->all(), $data['costume_outfits']);
                $data['costume_outfits'] = $hold['choices'];
                if ($event->status === 'capacity_reserved') {
                    $existing = BookingEvidence::where('event_id', $event->id)->firstOrFail();
                    $same = $event->reservations()->orderBy('resource_id')->pluck('resource_id')->all() === $ids->all();
                    abort_unless($same && ($existing->staff_assignment_snapshot['costume_choices'] ?? []) === $data['costume_outfits'] && $existing->method === $data['payment']['method'] && ($existing->received_at===null?($data['payment']['received_at']??null)===null:$existing->received_at->equalTo(CarbonImmutable::parse($data['payment']['received_at']))) && OperationalReview::where('event_id', $event->id)->firstOrFail()->evidence == $data['review'] && $existing->reference === $data['payment']['reference'] && $existing->amount_cents === (int) $data['payment']['amount_cents'], 409, 'La reserva ya está confirmada con otros datos.');
                    return $event;
                }
                abort_unless($event->status === 'draft' && $event->version === (int) $data['expected_version'], 409, 'El evento cambió. Actualiza la ficha.');
                if (!$event->address || $event->setup_minutes === null || $event->breakdown_minutes === null) {
                    $this->fail('Completa dirección, montaje y desmontaje.');
                }
                if ($event->starts_at->isPast()) {
                    $this->fail('No se puede reservar un evento pasado.');
                }
                // Internal owner confirmation keeps7days; the website has its separate14day approval path.
                if (\App\Services\Scheduling\BookingNoticePolicy::requiresOwnerConfirmationReason($event->starts_at) && empty($data['review']['short_notice_reason'])) {
                    $this->fail('Requiere aprobación por proximidad de fecha.');
                }
                \App\Services\Scheduling\DailyCapacity::assertRoom($event);
                $services = $event->services()->get();
                if ($services->isNotEmpty()) \App\Services\Availability\AdmitEventCapacity::assertFits($event); // day locks before character/resource locks
                // Match SaveResource's character→unit order. Hints never authorize a changed unit.
                $characterIds = $services->flatMap(fn ($s) => collect($s->requirements_snapshot)->where('kind', 'costume')->pluck('character_id'))
                    ->merge(Resource::whereIn('id', $ids)->where('kind', 'costume')->pluck('character_id'))->filter()->unique()->sort()->values();
                $characters = \App\Models\Character::whereIn('id', $characterIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $resources = Resource::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                if ($resources->count() !== $ids->count() || $resources->contains(fn($r) => $r->status !== 'active')) {
                    $this->fail('Hay recursos inexistentes o no disponibles.');
                }
                \App\Exceptions\RecordWriteRejected::unless(!$resources->contains(fn ($r) => $r->kind === 'costume' && !$characters->has((int) $r->character_id)), 409, 'Cambió el personaje de una unidad. Recarga los recursos.');
                $costumeChoices = collect($data['costume_outfits'])->keyBy('resource_id')->all();
                CostumeOutfits::validate($costumeChoices, $resources, $characters);
                if ($services->isEmpty()) {
                    $this->fail('Selecciona servicios con requisitos completos.');
                }
                if ($event->travel_fee_cents === null) {
                    $this->fail('Revisa el costo de transporte; no se asume cero.');
                }
                $total = \App\Models\PriceVersion::whereIn('id', $services->pluck('price_version_id'))->sum('amount_cents') + $event->travel_fee_cents;
                $importLink=\App\Models\ImportedProjectLink::where('event_id',$eventId)->lockForUpdate()->first();
                if($importLink){
                    $imported=\App\Models\ImportedProjectRevision::findOrFail($importLink->current_revision_id);
                    $expected=\App\Services\Imports\ImportedAgreement::payment($importLink,$imported);
                    \App\Exceptions\RecordWriteRejected::unless(($data['imported_revision_id']??null)===$imported->id&&$event->version===$imported->event_version
                        &&$data['payment']===$expected&&hash_equals($imported->mapping['operations_sha256'],\App\Services\Imports\ImportedAgreement::operations($event)),409,'Confirma este evento desde su acuerdo y dinero histórico importados.');
                    $total=$imported->normalized['total_cents'];
                }else{\App\Exceptions\RecordWriteRejected::unless(($data['payment']['method']??'')!=='honeybook_import',409,'No hay un acuerdo importado para esta evidencia.');}
                if ($conversion=\App\Models\QuoteConversion::where('event_id',$eventId)->first()) {
                    $finance=\App\Models\FinancialRevision::where('event_id',$eventId)->orderByDesc('revision')->firstOrFail();
                    $accepted=\App\Services\Quotes\QuoteAgreement::forEvent($event);
                    $net=(int)\App\Models\LedgerEntry::where('event_id',$eventId)->where('environment','test')->where('kind','receipt')->sum('amount_cents')
                        -(int)\App\Models\LedgerEntry::where('event_id',$eventId)->where('environment','test')->where('kind','refund')->sum('amount_cents');
                    \App\Exceptions\RecordWriteRejected::unless((int)($data['financial_revision_id']??0)===$finance->id&&$finance->event_version===$event->version
                        &&$finance->quote_revision_id===$conversion->quote_revision_id&&$finance->total_cents===$accepted['total_cents']
                        &&$net>0&&(int)$data['payment']['amount_cents']===$net&&$data['payment']['reference']==='ledger:test:'.$eventId.':'.$finance->id,
                        409,'Confirma esta cotización desde sus pagos reales y su revisión financiera vigente.');
                    $total=$finance->total_cents;
                }
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
                    foreach ($service->requirements_snapshot as $requirementIndex => $req) {
                        for ($i = 0; $i < $req['quantity']; $i++) {
                            $slots[] = [...$req, 'event_service_id'=>$service->id, 'requirement_index'=>$requirementIndex, 'occurrence'=>$i];
                        }
                    }
                }
                foreach (collect($slots)->where('kind', 'costume')->filter(fn ($s) => isset($s['outfit']))->groupBy(fn ($s) => json_encode([(int) $s['character_id'], $s['outfit']])) as $requested) {
                    $slot = $requested->first();
                    $matching = $resources->filter(fn ($r) => CostumeOutfits::fits($slot, $r, $characters, $costumeChoices));
                    if ($matching->count() < $requested->count()) {
                        $name = \App\Models\Character::whereKey($slot['character_id'])->value('name');
                        throw new \App\Exceptions\RecordWriteRejected(409, "Selecciona suficientes trajes {$name} ({$slot['outfit']}); no se sustituye la versión.");
                    }
                }
                // Merge costume-wearer constraints into performer roles before matching.
                // This solves all simultaneous staff roles together instead of choosing
                // a face painter first and rejecting an otherwise valid crew pairing.
                $costumes = $resources->where('kind', 'costume')->values();
                $compatibilities = DB::table('costume_compatibilities')->whereIn('costume_id', $costumes->pluck('id'))->sharedLock()->get();
                foreach ($costumes as $costume) {
                    $performerSlot = null;
                    foreach ($slots as $index => $slot) {
                        if ($slot['kind'] === 'staff' && ($slot['skill'] ?? null) === 'performer' && !isset($slot['costume_unit_id'])) {
                            $performerSlot = $index;
                            break;
                        }
                    }
                    if ($performerSlot === null) {
                        $slots[] = ['kind' => 'staff', 'skill' => 'performer', 'costume_unit_id' => $costume->id, 'event_service_id'=>null, 'requirement_index'=>null, 'occurrence'=>0, 'compiled_role'=>'costume_performer'];
                    } else {
                        $slots[$performerSlot]['costume_unit_id'] = $costume->id;
                    }
                }
                $assignment=app(MatchEventResources::class)->execute($slots, $resources->all(), function ($slot, $r) use ($compatibilities, $characters, $costumeChoices, $costumes) {
                    return $r->kind === $slot['kind'] && ($slot['kind'] !== 'costume' || CostumeOutfits::fits($slot, $r, $characters, $costumeChoices)) && (empty($slot['skill']) || in_array($slot['skill'], $r->skills ?? [], true)) && (empty($slot['character_id']) || (int) $r->character_id === (int) $slot['character_id']) && (empty($slot['equipment_kind']) || $r->equipment_kind === $slot['equipment_kind']) && (empty($slot['costume_unit_id']) || \App\Support\CostumeWearers::allows($costumes->firstWhere('id', $slot['costume_unit_id']), $r, $characters->get((int) $costumes->firstWhere('id', $slot['costume_unit_id'])?->character_id), $compatibilities));
                });
                $assignedStaff=collect(array_keys($assignment))->map(fn($i)=>$resources[$i])->where('kind','staff');
                $driverIds=$assignedStaff->filter(fn($r)=>MatchEventResources::driver($r))->pluck('id')->values()->all();
                if($assignedStaff->isNotEmpty()&&$driverIds===[])$this->fail('Falta alguien que maneje en el equipo.');
                $vehicles = $resources->where('kind', 'vehicle');
                if ($vehicles->isNotEmpty()) {
                    if ($vehicles->contains(fn($v) => !$v->seats) || $vehicles->sum('seats') < $resources->where('kind', 'staff')->count()) {
                        $this->fail('La capacidad de pasajeros no alcanza o está incompleta.');
                    }
                    if (count($driverIds) < $vehicles->count()) {
                        $this->fail('Faltan conductores para los vehículos seleccionados.');
                    }
                }
                $busyStart = $event->starts_at->subMinutes($event->setup_minutes);
                $busyEnd = $event->ends_at->addMinutes($event->breakdown_minutes);
                foreach ($resources->sortBy(fn ($r) => $r->kind === 'costume' ? 0 : 1) as $resource) {
                    if (ResourceBlock::where('resource_id', $resource->id)->whereNull('released_at')->where('starts_at', '<', $busyEnd)->where('ends_at', '>', $busyStart)->lockForUpdate()->first()) {
                        $this->rejectVersionConflict($resource, $slots, $characters, $costumeChoices);
                        $this->fail("El recurso {$resource->code} tiene un bloqueo horario.");
                    }
                    // Current reads after the resource lock: a repeatable-read snapshot may predate a competing commit.
                    $bookings = ResourceReservation::where('resource_id', $resource->id)->whereNotIn('id', $hold['reservation_ids'])->with(['event' => fn ($query) => $query->sharedLock()])->orderBy('starts_at')->lockForUpdate()->get();
                    $before = null;
                    $after = null;
                    foreach ($bookings as $booking) {
                        $other = $booking->event;
                        if ($other->starts_at->subMinutes($other->setup_minutes)->lessThan($busyEnd) && $other->ends_at->addMinutes($other->breakdown_minutes)->greaterThan($busyStart)) {
                            $this->rejectVersionConflict($resource, $slots, $characters, $costumeChoices);
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
                $costumeAssignments = [];
                foreach ($costumes as $unit) {
                    $slotIndex = $assignment[$resources->search(fn ($r) => $r->id === $unit->id)] ?? null;
                    $costumeAssignments[$unit->id] = CostumeOutfits::snapshot($unit, $slotIndex === null ? null : $slots[$slotIndex], $characters, $costumeChoices);
                }
                $staffSnapshot=['policy_version'=>'event-driver-v1','minimum_drivers'=>max($assignedStaff->isNotEmpty()?1:0,$vehicles->count()),'assigned_driver_ids'=>$driverIds,
                    'costume_policy_version'=>'costume-outfit-v2', 'costume_choices'=>$data['costume_outfits'], 'costume_assignments'=>array_values($costumeAssignments),
                    'assignments'=>collect($assignment)->map(function ($slot, $resource) use ($resources, $slots, $costumeAssignments) {
                        $chosenSlot = $slots[$slot];
                        if ($resources[$resource]->kind === 'staff' && isset($chosenSlot['costume_unit_id'], $costumeAssignments[$chosenSlot['costume_unit_id']])) {
                            $costume = $costumeAssignments[$chosenSlot['costume_unit_id']];
                            $chosenSlot = [...$chosenSlot, 'character_id'=>$costume['character_id'], 'character_name'=>$costume['character_name'], 'outfit'=>$costume['outfit']];
                        }
                        return ['resource_id'=>$resources[$resource]->id,'kind'=>$resources[$resource]->kind,'skills'=>$resources[$resource]->skills??[],'slot'=>$chosenSlot];
                    })->values()->all()];
                BookingEvidence::create([...$data['payment'], 'event_id' => $event->id, 'verified_by' => $actor->id,'staff_assignment_snapshot'=>$staffSnapshot]);
                foreach ($resources as $resource) {
                    if (ResourceReservation::where('event_id', $event->id)->where('resource_id', $resource->id)->whereIn('id', $hold['reservation_ids'])->exists()) continue;
                    ResourceReservation::create(['event_id' => $event->id, 'resource_id' => $resource->id, 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at]);
                }
                OperationalReview::create(['event_id' => $event->id, 'reviewed_by' => $actor->id, 'evidence' => $data['review']]);
                $event->status = 'capacity_reserved';
                // Contract/payment lifecycle remains separate for Phase 4.
                $event->version++;
                $event->save();
                \App\Services\Booking\QuickCostumeHolds::consume($actor, $event);
                Audit::recordFor($actor, 'booking.capacity_reserved', $event, ['status' => 'draft'], ['resource_ids' => $ids->all(), 'version' => $event->version, 'booking_evidence_id' => BookingEvidence::where('event_id', $event->id)->value('id')]);
                return $event;
            }, 3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $this->fail('La referencia o asignación ya fue registrada. Actualiza y revisa el evento.');
        }
    }
    private function rejectVersionConflict(Resource $resource, array $slots, \Illuminate\Support\Collection $characters, array $choices): void
    {
        if ($resource->kind !== 'costume') return;
        $versioned = collect($slots)->first(fn ($slot) => $slot['kind'] === 'costume' && isset($slot['outfit']) && CostumeOutfits::fits($slot, $resource, $characters, $choices));
        if ($versioned) {
            $name = $characters->get((int) $resource->character_id)?->name;
            throw new \App\Exceptions\RecordWriteRejected(409, "No hay traje {$name} ({$versioned['outfit']}) libre a esa hora.");
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
}
