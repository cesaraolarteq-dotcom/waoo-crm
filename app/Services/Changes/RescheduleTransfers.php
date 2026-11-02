<?php
namespace App\Services\Changes;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, ResourceBlock, ResourceReservation};
use App\Services\Finance\MutationKey;
use App\Services\Routing\DriveMinutes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Private, short-lived route proof. All provider I/O precedes the admission transaction. */
final class RescheduleTransfers
{
    public function prepare(Event $event, CarbonImmutable $start): array
    {
        RecordWriteRejected::unless(DB::transactionLevel() === 0, 409, 'Revisa el traslado antes de cambiar la fecha.');
        $snapshot = $this->snapshot($event, $start, false);
        $routes = new DriveMinutes(3, 2.0);
        $proof = ['snapshot' => MutationKey::hash($snapshot), 'read_at' => now()->timestamp, 'routes' => []];
        foreach ($this->edges($snapshot) as $key => [$from, $to, $resourceId]) {
            $routeKey = $from['id'].':'.$to['id'];
            if (array_key_exists($routeKey, $proof['routes'])) continue;
            $minutes = null;
            if ($from['breakdown'] !== null && $to['setup'] !== null && filled($from['address']) && filled($to['address'])) {
                $departure = CarbonImmutable::parse($from['end'])->addMinutes($from['breakdown']);
                $minutes = app()->bound('waoo.availability.drive')
                    ? app('waoo.availability.drive')($from['zip'], $to['zip'], $departure, $from['address'], $to['address'])
                    : $routes->betweenAddresses($from['address'], $to['address'], $departure);
            }
            $proof['routes'][$routeKey] = is_int($minutes) && $minutes >= 0 && $minutes <= 1440 ? $minutes : null;
        }
        return $proof;
    }

    /** Caller holds the global admission mutex, event, and all its reserved resource rows. */
    public function assertFits(Event $event, CarbonImmutable $start, array $proof): void
    {
        RecordWriteRejected::unless(DB::transactionLevel() > 0, 409, 'No se pudo revisar la agenda.');
        $snapshot = $this->snapshot($event, $start, true);
        RecordWriteRejected::unless(now()->timestamp >= $proof['read_at'] && now()->timestamp - $proof['read_at'] <= 120
            && hash_equals($proof['snapshot'], MutationKey::hash($snapshot)), 409, 'La agenda cambió mientras se revisaba el traslado. Vuelve a intentarlo.');
        foreach ($this->edges($snapshot) as $key => [$from, $to, $resourceId]) {
            $minutes = $proof['routes'][$from['id'].':'.$to['id']] ?? null;
            RecordWriteRejected::unless(is_int($minutes) && $from['breakdown'] !== null && $to['setup'] !== null,
                409, 'No se pudo comprobar el traslado del recurso apartado. Revisa dirección, montaje y desmontaje.');
            $departure = CarbonImmutable::parse($from['end'])->addMinutes($from['breakdown']);
            $arrival = $departure->addMinutes($minutes);
            RecordWriteRejected::unless($arrival->addMinutes(30 + $to['setup'])->lessThanOrEqualTo(CarbonImmutable::parse($to['start'])),
                409, 'No cabe el traslado con montaje, desmontaje y 30 minutos adicionales.');
            RecordWriteRejected::unless(!$arrival->greaterThan($departure) || ! ResourceBlock::where('resource_id', $resourceId)->whereNull('released_at')
                ->where('starts_at', '<', $arrival)->where('ends_at', '>', $departure)->lockForUpdate()->exists(),
                409, 'Un bloqueo del recurso impide el traslado entre fiestas.');
        }
    }

    private function snapshot(Event $event, CarbonImmutable $start, bool $lock): array
    {
        $ownQuery = ResourceReservation::where('event_id', $event->id)->orderBy('resource_id');
        $own = ($lock ? $ownQuery->lockForUpdate() : $ownQuery)->get();
        RecordWriteRejected::unless($own->count() <= 256, 409, 'La agenda requiere revisión por volumen.');
        $query = ResourceReservation::whereIn('resource_id', $own->pluck('resource_id'))->where('event_id', '!=', $event->id)
            ->with(['event' => fn ($q) => $lock ? $q->sharedLock() : $q])->orderBy('id')->limit(10001);
        $neighbors = ($lock ? $query->lockForUpdate() : $query)->get();
        RecordWriteRejected::unless($neighbors->count() <= 10000, 409, 'La agenda requiere revisión por volumen.');
        $candidate = $this->eventData($event);
        $candidate['start'] = $start->toIso8601String();
        $candidate['end'] = $start->addSeconds($event->starts_at->diffInSeconds($event->ends_at))->toIso8601String();
        return ['original' => $this->eventData($event), 'candidate' => $candidate,
            'own' => $own->map(fn ($r) => [$r->id, $r->resource_id, $r->starts_at->toIso8601String(), $r->ends_at->toIso8601String()])->all(),
            'neighbors' => $neighbors->map(fn ($r) => ['id' => $r->id, 'resource' => $r->resource_id,
                'start' => $r->starts_at->toIso8601String(), 'end' => $r->ends_at->toIso8601String(), 'event' => $this->eventData($r->event)])->all()];
    }

    private function eventData(Event $event): array
    {
        return ['id' => $event->id, 'version' => $event->version, 'status' => $event->status,
            'start' => $event->starts_at->toIso8601String(), 'end' => $event->ends_at->toIso8601String(),
            'setup' => $event->setup_minutes, 'breakdown' => $event->breakdown_minutes, 'address' => $event->address, 'zip' => $event->postal_code];
    }

    private function edges(array $snapshot): array
    {
        $candidate = $snapshot['candidate']; $edges = [];
        foreach ($snapshot['own'] as [, $resourceId]) {
            $before = null; $after = null;
            foreach ($snapshot['neighbors'] as $row) {
                if ($row['resource'] !== $resourceId) continue;
                $other = $row['event'];
                if ($other['end'] <= $candidate['start'] && ($before === null || $other['end'] > $before['end'])) $before = $other;
                if ($other['start'] >= $candidate['end'] && ($after === null || $other['start'] < $after['start'])) $after = $other;
            }
            foreach ([[$before, $candidate], [$candidate, $after]] as [$from, $to]) {
                if ($from === null || $to === null) continue;
                // Include the unit so every resource's blocks are checked, even when its route is shared by the crew.
                $edges[$resourceId.':'.$from['id'].':'.$to['id']] = [$from, $to, $resourceId];
            }
        }
        return $edges;
    }
}
