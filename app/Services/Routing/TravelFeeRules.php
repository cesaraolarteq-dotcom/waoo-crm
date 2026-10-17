<?php
namespace App\Services\Routing;

use App\Services\Finance\MutationKey;

final class TravelFeeRules
{
    public static function rules(): array { return config('travel_fees'); }
    public static function hash(): string { return MutationKey::hash(array_diff_key(self::rules(), array_flip(['google_calls_per_day','cache_days']))); }
    public static function normalize(string $address, ?string $zip): array
    {
        $normal = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $address)));
        $state = null; $embedded = null;
        if (preg_match('/\b([a-z]{2})[ ,]+(\d{5})(?:-\d{4})?(?:[ ,]+(?:usa|united states))?$/i', $normal, $m)) { $state = strtoupper($m[1]); $embedded = $m[2]; }
        $postal = filled($zip) ? substr(trim($zip), 0, 5) : $embedded;
        $reason = $embedded && $postal !== $embedded ? 'zip_mismatch' : ($postal === null ? 'zip_required' : null);
        $n = (int) $postal;
        $outside = ($state !== null && $state !== 'TX') || ($postal !== null && !(($n >= 75000 && $n <= 79999) || ($n >= 88500 && $n <= 88599)));
        return ['address' => trim($address), 'normalized_address' => $normal, 'zip' => $postal, 'reason' => $reason, 'outside' => $outside];
    }
    public static function inputHash(array $inputs): string { return MutationKey::hash(['address' => $inputs['normalized_address'], 'zip' => $inputs['zip']]); }
    public static function calculate(float $oneWayMiles, int $oneWayTollCents): array
    {
        $cfg = self::rules(); $raw = (float) $cfg['flat_fee']; $lo = (float) $cfg['flat_up_to_miles']; $tiers = [];
        foreach ($cfg['tiers'] as $tier) {
            if ($oneWayMiles <= $lo) break;
            $hi = $tier['up_to'] === null ? $oneWayMiles : min($oneWayMiles, (float) $tier['up_to']);
            $amount = $tier['rate'] * 2 * ($hi - $lo); $raw += $amount;
            $tiers[] = ['from_miles' => $lo, 'to_miles' => $hi, 'rate_cents' => (int) round($tier['rate'] * 100), 'amount_cents' => (int) round($amount * 100)];
            if ($tier['up_to'] === null) break;
            $lo = (float) $tier['up_to'];
        }
        $raw += $oneWayTollCents * 2 / 100; $step = max(1, (float) $cfg['round_to']);
        return ['fee_cents' => (int) (round($raw / $step) * $step * 100), 'raw_cents' => (int) round($raw * 100),
            'one_way_miles' => $oneWayMiles, 'round_trip_miles' => $oneWayMiles * 2, 'round_trip_tolls_cents' => 2 * $oneWayTollCents, 'tiers' => $tiers];
    }
}
