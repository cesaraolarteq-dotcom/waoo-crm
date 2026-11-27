<?php
namespace App\Support;

use App\Models\ServiceVariant;

/**
 * Owner 2026-11-20: a client sees each package in their own language, English by default; the contract is always English.
 * The owner keeps the Spanish name in his screens. Snapshots keep the Spanish name; the English one is looked up by it.
 */
final class PackageName
{
    public static function for(?string $spanish, ?string $locale = 'en'): string
    {
        $spanish = (string) $spanish;
        if ($locale === 'es' || $spanish === '') return $spanish;
        $en = ServiceVariant::where('name', $spanish)->whereNotNull('name_en')->value('name_en');
        return is_string($en) && trim($en) !== '' ? $en : $spanish;
    }
}
