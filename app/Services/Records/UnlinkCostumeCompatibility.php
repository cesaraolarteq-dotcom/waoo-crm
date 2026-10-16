<?php

namespace App\Services\Records;

use App\Exceptions\RecordWriteRejected;
use App\Models\Resource;
use App\Models\ResourceReservation;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;

final class UnlinkCostumeCompatibility
{
    /** @return array{staff_id: int, costume_id: int, removed: bool} */
    public function execute(User $actor, int $staffId, int $costumeId, string $reason): array
    {
        $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate(compact('reason'), ['reason' => 'required|string|min:10|max:1000']);

        return DB::transaction(function () use ($actor, $staffId, $costumeId, $data) {
            $rows = Resource::whereIn('id', [$staffId, $costumeId])->orderBy('id')->lockForUpdate()->get();
            $staff = $rows->firstWhere('id', $staffId);
            $costume = $rows->firstWhere('id', $costumeId);
            if (! $staff || ! $costume) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(Resource::class);
            }
            RecordWriteRejected::unless($staff->kind === 'staff' && $costume->kind === 'costume', 422, 'Selecciona una persona y un traje.');
            $ids = ['staff_id' => $staffId, 'costume_id' => $costumeId];
            $link = DB::table('costume_compatibilities')->where($ids);
            if (! $link->exists()) {
                return [...$ids, 'removed' => false];
            }
            $shared = ResourceReservation::where('resource_id', $staffId)->where('ends_at', '>', now())
                ->whereIn('event_id', ResourceReservation::select('event_id')->where('resource_id', $costumeId)->where('ends_at', '>', now()))->exists();
            RecordWriteRejected::when($shared, 409, 'Esta persona y este traje tienen un evento comprometido. Reasigna primero sus recursos.');
            $link->delete();
            Audit::recordFor($actor, 'costume.compatibility_removed', $costume, $ids, [...$ids, 'removed' => true, 'reason' => $data['reason']]);

            return [...$ids, 'removed' => true];
        }, 3);
    }
}
