<?php

namespace App\Services\Records;

use App\Models\PriceVersion;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AddPriceVersion
{
    public function execute(User $actor, int $variantId, array $input): PriceVersion
    {
        $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['amount_cents' => 'required|integer|min:0|max:100000000', 'currency' => ['required', Rule::in(['USD'])]]);
        return DB::transaction(function () use ($actor, $variantId, $data) {
            ServiceVariant::findOrFail($variantId);
            $price = PriceVersion::create([...$data, 'service_variant_id' => $variantId, 'created_by' => $actor->id]);
            Audit::recordFor($actor, 'price.version_created', $price, [], $price->getAttributes());
            return $price->fresh();
        });
    }
}
