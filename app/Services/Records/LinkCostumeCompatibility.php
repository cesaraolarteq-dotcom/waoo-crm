<?php

namespace App\Services\Records;

use App\Models\Resource;
use App\Models\User;
use App\Services\Audit;
use App\Exceptions\RecordWriteRejected;
use Illuminate\Support\Facades\DB;

final class LinkCostumeCompatibility
{
    /** @return array{staff_id: int, costume_id: int} */
    public function execute(User $actor, int $staffId, int $costumeId): array
    {
        $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate(['staff_id' => $staffId, 'costume_id' => $costumeId], ['staff_id' => 'required|integer|exists:resources,id', 'costume_id' => 'required|integer|exists:resources,id']);
        return DB::transaction(function () use ($actor, $data) {
            $rows = Resource::whereIn('id', [$data['staff_id'], $data['costume_id']])->orderBy('id')->lockForUpdate()->get();
            $staff = $rows->firstWhere('id', (int) $data['staff_id']);
            $costume = $rows->firstWhere('id', (int) $data['costume_id']);
            RecordWriteRejected::unless($staff?->kind === 'staff' && $costume?->kind === 'costume', 422, 'Selecciona una persona y un traje.');
            DB::table('costume_compatibilities')->updateOrInsert($data, []);
            Audit::recordFor($actor, 'costume.compatibility_added', $costume, [], ['staff_id' => $staff->id]);
            return $data;
        });
    }
}
