<?php

namespace App\Services\Records;

use App\Models\Character;
use App\Models\User;
use App\Services\Audit;
use App\Exceptions\RecordWriteRejected;
use Illuminate\Support\Facades\DB;

final class SaveCharacter
{
    public function execute(User $actor, array $input, ?int $characterId = null): Character
    {
        $actor = RecordActor::authorize($actor);
        $id = $characterId;
        $data = RecordRules::validate('characters', $input, $id);
        foreach (['category', 'group'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $data[$field] === null ? null : trim($data[$field]);
                if ($data[$field] === '') $data[$field] = null;
            }
        }
        if (array_key_exists('audience', $data)) $data['audience'] ??= 'all';
        $version = isset($data['expected_version']) ? (int) $data['expected_version'] : null;
        unset($data['expected_version']);
        return DB::transaction(function () use ($actor, $id, $data, $version) {
            $model = $id ? Character::whereKey($id)->lockForUpdate()->firstOrFail() : new Character();
            if ($id) {
                RecordWriteRejected::unless($model->version === $version, 409, 'La ficha cambió. Actualiza antes de guardar.');
            }
            if ($id && array_key_exists('outfits', $data)) {
                $referenced = \App\Models\Resource::where('character_id', $id)->where('kind', 'costume')->where('status', 'active')->whereNotNull('outfit')->pluck('outfit');
                foreach ($referenced as $outfit) {
                    RecordWriteRejected::unless(in_array($outfit, $data['outfits'] ?? [], true), 409, "La versión {$outfit} sigue vinculada a un traje activo.");
                }
            }
            if ($id) \App\Services\Booking\QuickStripeLeases::protectCharacter($model, $data);
            if ($id) \App\Services\Booking\QuickCostumeHolds::protectCharacter($model, $data);
            $before = $model->getAttributes();
            $model->fill($data);
            if ($id) $model->version++;
            $model->save();
            Audit::recordFor($actor, 'characters.' . ($id ? 'updated' : 'created'), $model, $before, $model->getAttributes());
            return $model->fresh();
        }, 3);
    }
}
