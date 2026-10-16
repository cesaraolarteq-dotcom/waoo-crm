<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Services\Records\RecordInput;
use Illuminate\Support\Facades\DB;

final class MutationKey
{
    public static function hash(array $input): string
    {
        $sort = function (array $data) use (&$sort): array {
            if (! array_is_list($data)) ksort($data);
            foreach ($data as $key => $value) if (is_array($value)) $data[$key] = $sort($value);
            return $data;
        };
        return hash('sha256', json_encode($sort($input), JSON_THROW_ON_ERROR));
    }
    public static function find(string $scope, string $key, string $hash): ?object
    {
        RecordInput::validate(['idempotency_key' => $key], ['idempotency_key' => 'required|uuid']);
        $row = DB::table('mutation_receipts')->where(['scope' => $scope, 'request_key' => $key])->first();
        if ($row) RecordWriteRejected::unless(hash_equals($row->request_hash, $hash), 409, 'Esta clave ya se usó con otros datos.');
        return $row;
    }
    public static function store(string $scope, string $key, string $hash, string $type, int|string $id): void
    {
        DB::table('mutation_receipts')->insert(['scope' => $scope, 'request_key' => $key, 'request_hash' => $hash,
            'result_type' => $type, 'result_id' => (string) $id, 'created_at' => now(), 'updated_at' => now()]);
    }
}
