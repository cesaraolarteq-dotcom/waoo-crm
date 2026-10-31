<?php
namespace App\Services\Availability;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, ImportedProjectLink, User};
use App\Services\Audit;
use App\Services\Records\{RecordActor, RecordInput};
use App\Services\Scheduling\DailyCapacity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Owner-verified, private source identity. A timestamp match by itself never creates a binding. */
final class BindCalendarIdentity
{
    public function execute(User $actor, int $eventId, array $input): int
    {
        RecordActor::authorize($actor);
        $data = RecordInput::validate($input, [
            'expected_event_version' => 'required|integer|min:1', 'uid' => 'required|string|max:1024',
            'verified_same_event' => 'required|accepted', 'reason' => 'required|string|min:10|max:1000',
        ]);
        $scope = HoneyBookCalendarFeed::scope();
        $uid = HoneyBookCalendarFeed::uidHash($data['uid']);
        RecordWriteRejected::unless($scope !== null && $uid !== null && DB::transactionLevel() === 0, 409, 'No se pudo verificar la identidad del calendario.');
        $hint = Event::findOrFail($eventId);
        RecordWriteRejected::unless($hint->version === (int) $data['expected_event_version']
            && ImportedProjectLink::where('event_id', $eventId)->exists(), 409, 'El evento importado cambió. Actualiza y vuelve a revisar.');
        $from = CarbonImmutable::instance($hint->starts_at); $to = CarbonImmutable::instance($hint->ends_at);
        $entries = app(HoneyBookCalendarFeed::class)->between($from->subDay(), $to->addDay());
        $matched = array_filter($entries ?? [], fn ($e) => ($e[2]['uid_sha256'] ?? null) === $uid
            && ($e[2]['scope_sha256'] ?? null) === $scope && $e[0]->equalTo($from) && $e[1]->equalTo($to));
        RecordWriteRejected::unless(count($matched) === 1, 409, 'La identidad y el horario del calendario no coinciden con este evento.');

        return DB::transaction(function () use ($actor, $eventId, $data, $scope, $uid, $from, $to) {
            DailyCapacity::lock();
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); RecordActor::authorize($actor);
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            RecordWriteRejected::unless($event->version === (int) $data['expected_event_version']
                && $event->starts_at->equalTo($from) && $event->ends_at->equalTo($to)
                && ImportedProjectLink::where('event_id', $eventId)->exists()
                && HoneyBookCalendarFeed::scope() === $scope, 409, 'El evento importado cambió. Actualiza y vuelve a revisar.');
            $existing = DB::table('calendar_identity_links')->where('scope_sha256', $scope)
                ->where(fn ($q) => $q->where('uid_sha256', $uid)->orWhere('event_id', $eventId))->lockForUpdate()->get();
            RecordWriteRejected::unless($existing->every(fn ($r) => $r->uid_sha256 === $uid && (int) $r->event_id === $eventId),
                409, 'Esta identidad ya está asociada a otro evento. Revisa la asociación existente.');
            $values = ['scope_sha256' => $scope, 'uid_sha256' => $uid, 'event_id' => $eventId,
                'starts_at' => $from->utc()->format('Y-m-d H:i:s'), 'ends_at' => $to->utc()->format('Y-m-d H:i:s')];
            $old = $existing->first();
            if ($old && $old->starts_at === $values['starts_at'] && $old->ends_at === $values['ends_at']) return (int) $old->id;
            $record = [...$values, 'verified_by' => $actor->id, 'updated_at' => now()];
            if ($old) { DB::table('calendar_identity_links')->where('id', $old->id)->update($record); $id = (int) $old->id; }
            else $id = DB::table('calendar_identity_links')->insertGetId([...$record, 'created_at' => now()]);
            Audit::recordFor($actor, 'calendar.identity_verified', $event, [], [...$values, 'binding_id' => $id, 'reason' => $data['reason']]);
            return $id;
        }, 3);
    }
}
