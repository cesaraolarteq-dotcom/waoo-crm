<?php
namespace App\Support;

/** Texas ZIP code centers (US Census 2024 ZCTA gazetteer, public data) for the Reportes zone map. */
final class ZipCentroids
{
    private static ?array $all = null;

    /** @return array{0: float, 1: float}|null [lat, lng] */
    public static function get(string $zip): ?array
    {
        self::$all ??= json_decode((string) @file_get_contents(resource_path('data/tx-zip-centroids.json')), true) ?: [];

        return self::$all[$zip] ?? null;
    }
}
