<?php

namespace App\Services\Imports;

use App\Models\{ImportBatch, User};

final class ReadHoneyBookProjectDiff
{
    public function execute(User $actor, int $batchId, int $rowId): array
    {
        $actor = ImportInput::actor($actor);
        return ImportPersistence::transaction(function () use ($actor, $batchId, $rowId) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            ImportInput::actor($actor);
            ImportInput::lock();
            $batch = ImportBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();
            return app(HoneyBookResyncReview::class)->context($batch, $rowId)['view'];
        });
    }
}
