<?php
namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, ImportedProjectLink, PortalGrant, User};

/** Serializes importer membership without acquiring an import link before its event. */
final class CutoverCohort
{
    public function run(User $actor, callable $work): mixed
    {
        $actor = CutoverAccess::actor($actor);
        $hints = ImportedProjectLink::orderBy('id')->limit(2001)->get(['id', 'event_id']);
        RecordWriteRejected::unless($hints->count() <= 2000, 409, 'El grupo requiere una revisión por volumen antes de preparar el corte.');
        return ImportPersistence::transaction(function () use ($actor, $hints, $work) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            CutoverAccess::actor($actor); ImportInput::lock();
            $ids = $hints->pluck('event_id')->sort()->values()->all();
            $grants = PortalGrant::whereIn('event_id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'event_id', 'updated_at', 'scopes', 'revoked_at', 'expires_at']);
            $events = Event::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $links = ImportedProjectLink::orderBy('id')->limit(2001)->lockForUpdate()->get();
            RecordWriteRejected::unless($hints->map->only(['id', 'event_id'])->all() === $links->map->only(['id', 'event_id'])->all(),
                409, 'El grupo de HoneyBook cambió. Vuelve a revisar.');
            return $work($actor, $links, $events, $grants);
        });
    }
}
