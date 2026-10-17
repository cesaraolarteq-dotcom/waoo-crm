<?php
// Exact pure formula from waoo-website5afc001 travel-fee-lib.php lines31–43. No config/key/network load.
function fixture_website_travel_fee($oneWayMiles, $oneWayToll, array $cfg) {
    $raw = (float) $cfg['flat_fee'];
    $lo = (float) $cfg['flat_up_to_miles'];
    foreach ($cfg['tiers'] as $tier) {
        if ($oneWayMiles <= $lo) break;
        $hi = $tier['up_to'] === null ? $oneWayMiles : min($oneWayMiles, (float) $tier['up_to']);
        if ($hi > $lo) $raw += $tier['rate'] * 2 * ($hi - $lo);
        if ($tier['up_to'] === null) break;
        $lo = (float) $tier['up_to'];
    }
    $raw += $oneWayToll * 2;
    $step = max(1, (float) $cfg['round_to']);
    return ['raw' => $raw, 'fee' => (int) (round($raw / $step) * $step)];
}
