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
            // Serialize version validation/removal before taking the unit lock.
            $character = !empty($data['character_id']) ? \App\Models\Character::whereKey($data['character_id'])->lockForUpdate()->firstOrFail() : null;
            $model = $id ? Resource::whereKey($id)->lockForUpdate()->firstOrFail() : new Resource();
            if ($id && $version !== null) {
                RecordWriteRejected::unless($model->version === $version, 409, 'La ficha cambió. Actualiza antes de guardar.');
            }
            if ($id) {
                RecordWriteRejected::unless($model->kind === $data['kind'], 422, 'No cambies el tipo de una unidad; crea una ficha nueva.');
                RecordWriteRejected::when(ResourceReservation::where('resource_id', $id)->where('ends_at', '>', now())->sharedLock()->first() !== null, 409, 'Reasigna primero los compromisos de este recurso.');
            }
            $outfit = array_key_exists('outfit', $data) ? $data['outfit'] : $model->outfit;
            if ($outfit !== null && ($data['kind'] !== 'costume' || !$character || !in_array($outfit, $character->outfits ?? [], true))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['outfit' => 'La versión ya no existe para este personaje.']);
            }
            $before = $model->getAttributes();
            $model->fill($data);
            if ($id && $version !== null) $model->version++;
            $model->save();
            \App\Services\Staff\StaffGuard::disableResource($model);
            Audit::recordFor($actor, 'resources.' . ($id ? 'updated' : 'created'), $model, $before, $model->getAttributes());
            return $model->fresh();
        }, 3);
    }
}
