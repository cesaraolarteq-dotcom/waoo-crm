<?php

namespace App\Services\Records;

use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Models\User;
use App\Services\Audit;
use App\Exceptions\RecordWriteRejected;
use Illuminate\Support\Facades\DB;

final class ReleaseBlock
{
    public function execute(User $actor, int $resourceId, int $blockId, string $reason): ResourceBlock
    {
        $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate(['reason' => $reason], ['reason' => 'required|string|min:10|max:1000']);
        return DB::transaction(function () use ($actor, $resourceId, $blockId, $data) {
            Resource::whereKey($resourceId)->lockForUpdate()->firstOrFail();
            $record = ResourceBlock::where('resource_id', $resourceId)->whereKey($blockId)->lockForUpdate()->firstOrFail();
            if ($record->released_at !== null) {
                RecordWriteRejected::unless($record->release_reason === $data['reason'], 409, 'El bloqueo ya fue levantado con otro motivo. Consulta el historial.');
                return $record;
            }
            $before = $record->getAttributes();
            $record->released_at = now();
            $record->released_by = $actor->id;
            $record->release_reason = $data['reason'];
            $record->save();
            Audit::recordFor($actor, 'resource.block_released', $record, $before, $record->getAttributes());
            return $record;
        }, 3);
    }
}
