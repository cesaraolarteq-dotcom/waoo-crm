<?php
namespace App\Services\Records;

use App\Models\{PriceVersion, ServiceVariant};

/** Catalog hints only. Stored event buffers are independent, editable operating values. */
final class PackageTimingDefaults
{
    /** Same exact input types for catalog import and audited saves; booleans are not minutes. */
    public static function rules(): array
    {
        $integerType = static function (string $attribute, mixed $value, \Closure $fail): void {
            if (!is_int($value) && !is_string($value)) $fail('Este campo debe ser un número entero.');
        };
        return [
            'setup_minutes' => ['sometimes', 'nullable', $integerType, 'integer', 'min:0', 'max:720'],
            'breakdown_minutes' => ['sometimes', 'nullable', $integerType, 'integer', 'min:0', 'max:720'],
        ];
    }

    public static function forPriceIds(array $priceIds): array
    {
        $unknown = ['setup_minutes' => null, 'breakdown_minutes' => null];
        if ($priceIds === [] || count($priceIds) > 10) return $unknown;
        foreach ($priceIds as $id) {
            if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id < 1) return $unknown;
        }
        $ids = array_values(array_unique(array_map('intval', $priceIds)));
        $prices = PriceVersion::whereIn('id', $ids)->get(['id', 'service_variant_id']);
        if ($prices->count() !== count($ids)) return $unknown;
        $variantIds = $prices->pluck('service_variant_id')->unique()->values();
        $variants = ServiceVariant::whereIn('id', $variantIds)->get(['id', 'setup_minutes', 'breakdown_minutes']);
        return $variants->count() === $variantIds->count() ? self::fromVariants($variants) : $unknown;
    }

    /** @internal Creation passes its already locked variants, never a second unlocked catalog read. */
    public static function fromVariants(iterable $variants): array
    {
        $sources = collect($variants); $result = [];
        foreach (['setup_minutes', 'breakdown_minutes'] as $field) {
            $values = $sources->pluck($field);
            $known = $values->isNotEmpty() && $values->every(fn ($value) => is_int($value) && $value >= 0 && $value <= 720);
            $result[$field] = $known ? $values->max() : null;
        }
        return $result;
    }
}
