<?php
namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Character, Event, LedgerEntry, QuickBookingRecord, QuickCostumeHold, QuickCostumeHoldEvent, Resource, ResourceBlock, ResourceReservation, User};
use App\Services\{Audit, CostumeOutfits, MatchEventResources};
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Portal\PortalContext;
use App\Services\Records\{RecordActor, RecordInput};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared commitment protocol. No money creation, staff assignment or cancellation HTTP action. */
final class QuickCostumeHolds
{
    public static function actor(User $actor): User
    {
        Sandbox::require(); Sandbox::require('sandbox_documents'); PortalContext::requireTransport(request());
        return RecordActor::authorize($actor, DB::transactionLevel() > 0);
    }
    public static function record(Event $e): QuickBookingRecord
    {
        $q = QuickBookingRecord::where('event_id', $e->id)->sharedLock()->firstOrFail();
        $receipt = LedgerEntry::whereKey($q->receipt_id)->sharedLock()->firstOrFail();
        RecordWriteRejected::unless($e->customer_id === $q->customer_id && $e->responsible_system === 'crm'
            && $receipt->event_id === $e->id && $receipt->financial_revision_id === $q->initial_financial_revision_id
            && $receipt->kind === 'receipt' && $receipt->environment === 'test' && $receipt->currency === 'USD'
            && $receipt->amount_cents > 0 && $receipt->verified_by !== null && $receipt->source_type === 'owner_verified',
            409, 'La reserva o su depósito requieren revisión.');
        return $q;
    }
    public static function window(Event $e): array
    {
        return ['party_start' => $e->starts_at->toIso8601String(), 'party_end' => $e->ends_at->toIso8601String(),
            'setup_minutes' => $e->setup_minutes, 'breakdown_minutes' => $e->breakdown_minutes,
            'busy_start' => $e->starts_at->subMinutes($e->setup_minutes ?? 0)->toIso8601String(),
            'busy_end' => $e->ends_at->addMinutes($e->breakdown_minutes ?? 0)->toIso8601String()];
    }
    public static function recipe(Event $e): array
    {
        return $e->services()->with(['characters' => fn ($q) => $q->orderBy('id')])->orderBy('price_version_id')->get()->map(fn ($s) => [
            'price_version_id' => $s->price_version_id, 'duration_minutes' => $s->duration_minutes, 'ready' => $s->operational_ready,
            'requirements' => $s->requirements_snapshot, 'characters' => $s->characters->map(fn ($c) => $c->only(['character_id','quantity','outfit','note']))->all(),
        ])->all();
    }
    public static function slots(Event $e): array
    {
        $slots = []; $missing = false;
        foreach ($e->services()->get() as $service) {
            if (!$service->operational_ready && (\App\Models\ServiceVariant::whereKey($service->service_variant_id)->value('character_quantity') ?? 0) > 0) $missing = true;
            foreach ($service->requirements_snapshot ?? [] as $req) {
                if (($req['kind'] ?? null) !== 'costume') continue;
                RecordWriteRejected::unless((is_int($req['quantity'] ?? null) || (is_string($req['quantity'] ?? null) && ctype_digit($req['quantity']))) && $req['quantity'] > 0 && $req['quantity'] <= 20 && isset($req['character_id']), 409, 'Revisa la receta del personaje.');
                for ($i = 0; $i < $req['quantity']; $i++) $slots[] = $req;
            }
        }
        RecordWriteRejected::unless(count($slots) <= 100, 409, 'El apartado requiere revisión por volumen.');
        return ['slots' => $slots, 'missing' => $missing];
    }
    public static function characters(Event $e): array
    {
        $ids = collect(self::slots($e)['slots'])->pluck('character_id')->unique()->sort()->values();
        return Character::whereIn('id', $ids)->orderBy('id')->get(['id','version'])->map(fn ($c) => ['id' => $c->id, 'version' => $c->version])->all();
    }
    public static function verify(QuickCostumeHold $h, Event $e): array
    {
        try {
            $p = $h->payload; $q = self::record($e);
            $history = QuickCostumeHoldEvent::where('quick_costume_hold_id', $h->id)->where('version', $h->version)->sharedLock()->first();
            $valid = is_array($p) && ($p['schema_version'] ?? null) === 1 && $h->event_id === $e->id && $h->quick_booking_id === $q->id
                && $h->receipt_id === $q->receipt_id && $p['event_id'] === $e->id && $p['quick_booking_id'] === $q->id && $p['receipt_id'] === $q->receipt_id
                && $p['head_id'] === $h->id && $p['version'] === $h->version && $p['state'] === $h->state
                && hash_equals($h->payload_sha256, MutationKey::hash($p)) && $history && $history->payload_sha256 === $h->payload_sha256
                && $history->payload === $p;
            RecordWriteRejected::unless($valid, 409, 'La evidencia del apartado requiere revisión.');
            if (in_array($h->state, ['held','review_required','consumed'], true)) {
                foreach ($p['rows'] as $row) {
                    $reservation = ResourceReservation::whereKey($row['reservation_id'])->sharedLock()->first();
                    $unit = Resource::whereKey($row['resource_id'])->sharedLock()->first();
                    RecordWriteRejected::unless($reservation && $unit && $reservation->event_id === $e->id && $reservation->resource_id === $unit->id
                        && $reservation->starts_at->toIso8601String() === $p['window']['party_start'] && $reservation->ends_at->toIso8601String() === $p['window']['party_end']
                        && $unit->kind === 'costume' && $unit->status === 'active' && $unit->character_id === $row['character_id'] && $unit->version === $row['resource_version']
                        && ($unit->outfit === null || $unit->outfit === $row['outfit']),
                        409, 'Cambió el inventario apartado. Requiere revisión.');
                }
            }
            return $p;
        } catch (\Illuminate\Database\QueryException $e) { throw $e; }
        catch (RecordWriteRejected $e) { throw $e; }
        catch (\Throwable) { throw new RecordWriteRejected(409, 'La evidencia del apartado requiere revisión.'); }
    }
    public static function view(Event $e): array
    {
        self::record($e); $h = QuickCostumeHold::where('event_id', $e->id)->sharedLock()->first();
        $base = ['event_id' => $e->id, 'event_version' => $e->version, 'version' => $h?->version ?? 0,
            'state' => 'not_attempted', 'issues' => [], 'rows' => [], 'characters' => self::characters($e), 'staff_confirmed' => false];
        if (!$h) return $base;
        try {
            $p = self::verify($h, $e); $issues = $p['issues'];
            if (in_array($h->state, ['held','review_required'], true)) {
                if ($p['window'] !== self::window($e) || $p['recipe_sha256'] !== MutationKey::hash(self::recipe($e))) $issues[] = 'hold_review_required';
                foreach ($p['rows'] as $row) if (Character::whereKey($row['character_id'])->value('version') !== $row['character_version']) $issues[] = 'costume_catalog_changed';
            }
            return [...$base, 'state' => $issues !== [] && in_array($h->state, ['held','review_required'], true) ? 'review_required' : $h->state,
                'issues' => array_values(array_unique($issues)), 'rows' => $p['rows']];
        } catch (RecordWriteRejected) { return [...$base, 'state' => 'review_required', 'issues' => ['integrity_invalid']]; }
    }
    public static function transition(User $actor, Event $e, QuickCostumeHold $h, string $state, array $rows, array $issues, ?string $reason = null): QuickCostumeHoldEvent
    {
        $p = ['schema_version' => 1, 'head_id' => $h->id, 'event_id' => $e->id, 'quick_booking_id' => $h->quick_booking_id, 'receipt_id' => $h->receipt_id,
            'version' => $h->version + 1, 'state' => $state, 'rows' => $rows, 'issues' => $issues, 'window' => self::window($e),
            'recipe_sha256' => MutationKey::hash(self::recipe($e)), 'actor_id' => $actor->id, 'reason' => $reason];
        $hash = MutationKey::hash($p); $h->fill(['version' => $p['version'], 'state' => $state, 'payload' => $p, 'payload_sha256' => $hash])->save();
        $history = QuickCostumeHoldEvent::create(['quick_costume_hold_id' => $h->id, 'version' => $h->version, 'payload' => $p, 'payload_sha256' => $hash]);
        Audit::recordFor($actor, 'quick_costume_hold.'.$state, $h, [], ['event_id' => $e->id, 'version' => $h->version, 'sha256' => $hash]);
        return $history;
    }
    public static function available(Event $e, Resource $unit, array $exclude = []): bool
    {
        $window = self::window($e); $start = CarbonImmutable::parse($window['busy_start']); $end = CarbonImmutable::parse($window['busy_end']);
        if (ResourceBlock::where('resource_id', $unit->id)->whereNull('released_at')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->lockForUpdate()->first()) return false;
        $reservations = ResourceReservation::where('resource_id', $unit->id)->whereNotIn('id', $exclude)
            ->with(['event' => fn ($q) => $q->sharedLock()])->orderBy('id')->limit(10001)->lockForUpdate()->get();
        RecordWriteRejected::unless($reservations->count() <= 10000, 409, 'El inventario requiere revisión por volumen.');
        foreach ($reservations as $r) if ($r->event->starts_at->subMinutes($r->event->setup_minutes ?? 0)->lessThan($end)
            && $r->event->ends_at->addMinutes($r->event->breakdown_minutes ?? 0)->greaterThan($start)) return false;
        return true;
    }
    public static function confirmation(Event $e, array $resourceIds, array $choices): array
    {
        $h = QuickCostumeHold::where('event_id', $e->id)->lockForUpdate()->first();
        if (!$h || !in_array($h->state, ['held','review_required','consumed'], true)) return ['reservation_ids' => [], 'choices' => $choices];
        $p = self::verify($h, $e);
        RecordWriteRejected::unless($p['window'] === self::window($e) && $p['recipe_sha256'] === MutationKey::hash(self::recipe($e)), 409, 'Revisa el horario y los trajes apartados antes de confirmar.');
        $selected = collect($choices)->keyBy('resource_id');
        foreach ($p['rows'] as $row) {
            RecordWriteRejected::unless(in_array($row['resource_id'], $resourceIds, true), 409, 'Incluye todos los trajes ya apartados para esta fiesta.');
            $unit = Resource::whereKey($row['resource_id'])->firstOrFail();
            if ($unit->outfit === null && $row['outfit'] !== null) {
                $held = ['resource_id' => $unit->id, 'outfit' => $row['outfit'], 'expected_character_version' => $row['character_version']];
                RecordWriteRejected::unless(!$selected->has($unit->id) || $selected[$unit->id] === $held, 409, 'El vestuario ya está apartado con otra selección.');
                $selected[$unit->id] = $held;
            }
        }
        return ['reservation_ids' => array_column($p['rows'], 'reservation_id'), 'choices' => $selected->sortKeys()->values()->all()];
    }
    public static function consume(User $actor, Event $e): void
    {
        $h = QuickCostumeHold::where('event_id', $e->id)->lockForUpdate()->first();
        if ($h && in_array($h->state, ['held','review_required'], true)) {
            $p = self::verify($h, $e); self::transition($actor, $e, $h, 'consumed', $p['rows'], []);
        }
    }
    public static function refreshDraft(User $actor, Event $e): void
    {
        $h = QuickCostumeHold::where('event_id', $e->id)->lockForUpdate()->first();
        if (!$h || !in_array($h->state, ['held','review_required'], true)) return;
        $p = self::verify($h, $e); $w = self::window($e); $old = $p['window'];
        RecordWriteRejected::unless($p['recipe_sha256'] === MutationKey::hash(self::recipe($e)) && $old['party_start'] === $w['party_start'] && $old['party_end'] === $w['party_end']
            && ($old['setup_minutes'] === null || $old['setup_minutes'] === $w['setup_minutes'])
            && ($old['breakdown_minutes'] === null || $old['breakdown_minutes'] === $w['breakdown_minutes']), 409, 'Libera y revisa el apartado antes de cambiar horario, paquete o vestuario.');
        if ($w === $old) return;
        $units = Resource::whereIn('id', array_column($p['rows'], 'resource_id'))->orderBy('id')->lockForUpdate()->get();
        foreach ($units as $unit) RecordWriteRejected::unless(self::available($e, $unit, array_column($p['rows'], 'reservation_id')), 409, 'El montaje o desmontaje ampliado choca con otro apartado.');
        $issues = $e->setup_minutes === null || $e->breakdown_minutes === null ? ['timing_unknown'] : [];
        self::transition($actor, $e, $h, $issues ? 'review_required' : 'held', $p['rows'], $issues);
    }
    public static function protectCharacter(Character $character, array $data): void
    {
        $events = ResourceReservation::query()->join('resources', 'resources.id', '=', 'resource_reservations.resource_id')
            ->where('resources.character_id', $character->id)->where('resource_reservations.ends_at', '>', now())->sharedLock()->pluck('event_id');
        foreach (QuickCostumeHold::whereIn('event_id', $events)->whereIn('state', ['held','review_required','consumed'])->sharedLock()->get() as $h) {
            $e = Event::findOrFail($h->event_id); $p = self::verify($h, $e);
            RecordWriteRejected::unless($data['active'], 409, 'El personaje tiene trajes comprometidos.');
            foreach ($p['rows'] as $row) if ($row['character_id'] === $character->id && $row['outfit'] !== null)
                RecordWriteRejected::unless(in_array($row['outfit'], $data['outfits'] ?? $character->outfits ?? [], true), 409, 'Este vestuario sigue apartado para una fiesta.');
        }
    }
    public static function releaseForCancellation(User $actor, Event $e, string $reason): void
    {
        RecordWriteRejected::unless(DB::transactionLevel() > 0 && $e->status === 'cancelled', 409, 'La liberación requiere una cancelación autorizada y atómica.');
        $actor = self::actor($actor); RecordInput::validate(['reason' => $reason], ['reason' => 'required|string|min:10|max:1000']);
        $h = QuickCostumeHold::where('event_id', $e->id)->lockForUpdate()->first();
        if (!$h || !in_array($h->state, ['held','review_required','consumed'], true)) return;
        $p = self::verify($h, $e); Resource::whereIn('id', array_column($p['rows'], 'resource_id'))->orderBy('id')->lockForUpdate()->get();
        ResourceReservation::whereIn('id', array_column($p['rows'], 'reservation_id'))->where('event_id', $e->id)->delete();
        self::transition($actor, $e, $h, 'released', [], [], $reason);
    }
}
