<?php

namespace App\Services\Records;

use App\Models\Customer;
use App\Models\User;
use App\Services\Audit;
use App\Exceptions\RecordWriteRejected;
use Illuminate\Support\Facades\DB;

final class SaveCustomer
{
    public function execute(User $actor, array $input, ?int $customerId = null): Customer
    {
        $actor = RecordActor::authorize($actor);
        $id = $customerId;
        $data = RecordRules::validate('customers', $input, $id);
        $version = isset($data['expected_version']) ? (int) $data['expected_version'] : null;
        unset($data['expected_version']);
        return DB::transaction(function () use ($actor, $id, $data, $version) {
            $model = $id ? Customer::whereKey($id)->lockForUpdate()->firstOrFail() : new Customer();
            if ($id) {
                RecordWriteRejected::unless($model->version === $version, 409, 'La ficha cambió. Actualiza antes de guardar.');
            }
            $before = $model->getAttributes();
            $model->fill($data);
            if ($id) $model->version++;
            $model->save();
            Audit::recordFor($actor, 'customers.' . ($id ? 'updated' : 'created'), $model, $before, $model->getAttributes());
            return $model->fresh();
        });
    }
}
