<?php

namespace App\Services\Records;

use App\Models\Resource;
use App\Models\ResourceReservation;
use App\Models\User;
use App\Services\Audit;
use App\Exceptions\RecordWriteRejected;
use Illuminate\Support\Facades\DB;

final class SaveResource
{
    public function execute(User $actor, array $input, ?int $resourceId = null): Resource
    {
        $actor = RecordActor::authorize($actor);
        $id = $resourceId;
        $data = RecordRules::validate('resources', $input, $id);
        $version = $data['expected_version'] ?? null;
        unset($data['expected_version']);
        return DB::transaction(function () use ($actor, $id, $data, $version) {
            $model = $id ? Resource::whereKey($id)->lockForUpdate()->firstOrFail() : new Resource();
            if ($id && $version !== null) {
                RecordWriteRejected::unless($model->version === $version, 409, 'La ficha cambió. Actualiza antes de guardar.');
            }
            if ($id) {
                RecordWriteRejected::unless($model->kind === $data['kind'], 422, 'No cambies el tipo de una unidad; crea una ficha nueva.');
                RecordWriteRejected::when(ResourceReservation::where('resource_id', $id)->where('ends_at', '>', now())->exists(), 409, 'Reasigna primero los compromisos de este recurso.');
            }
            $before = $model->getAttributes();
            $model->fill($data);
            if ($id && $version !== null) $model->version++;
            $model->save();
            Audit::recordFor($actor, 'resources.' . ($id ? 'updated' : 'created'), $model, $before, $model->getAttributes());
            return $model->fresh();
        });
    }
}
