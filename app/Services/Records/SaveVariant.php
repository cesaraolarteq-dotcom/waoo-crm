<?php

namespace App\Services\Records;

use App\Models\ServiceVariant;
use App\Models\User;
use App\Services\Audit;
use App\Exceptions\RecordWriteRejected;
use Illuminate\Support\Facades\DB;

final class SaveVariant
{
    public function execute(User $actor, array $input, ?int $variantId = null): ServiceVariant
    {
        $actor = RecordActor::authorize($actor);
        $id = $variantId;
        $data = RecordRules::validate('variants', $input, $id);
        $version = $data['expected_version'] ?? null;
        unset($data['expected_version']);
        return DB::transaction(function () use ($actor, $id, $data, $version) {
            $model = $id ? ServiceVariant::whereKey($id)->lockForUpdate()->firstOrFail() : new ServiceVariant();
            if ($id && $version !== null) {
                RecordWriteRejected::unless($model->version === $version, 409, 'La ficha cambió. Actualiza antes de guardar.');
            }
            $before = $model->getAttributes();
            $model->fill($data);
            if ($id && $version !== null) $model->version++;
            $model->save();
            Audit::recordFor($actor, 'variants.' . ($id ? 'updated' : 'created'), $model, $before, $model->getAttributes());
            return $model->fresh();
        });
    }
}
