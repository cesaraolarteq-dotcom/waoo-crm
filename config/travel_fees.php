<?php
// Owner-approved website data (2026-10-29: departure point 23645 Katy Fwy). 2026-11-19 César: new formula fitted to his prices
// ($20 first 5 mi one-way, $1.25/mi to 40, $5/mi after, tolls included), limit 70 mi one-way, new fixed ZIPs. Same as the website; no credentials.
return [
    'version' => 'website-2026-11-19-per-mile', 'origin_address' => '23645 Katy Fwy, Katy, TX 77494', 'origin_zip' => '77494', 'toll_pass' => 'US_TX_EZTAG',
    'best_route_under_round_trip_miles' => 30, 'flat_up_to_miles' => 5, 'flat_fee' => 20,
    'tiers' => [['up_to' => 40, 'rate' => 0.625], ['up_to' => null, 'rate' => 2.50]], 'charge_tolls' => false,
    'default_one_way_toll' => 5, 'round_to' => 5, 'max_round_trip_miles' => 140, 'cache_days' => 30, 'google_calls_per_day' => 75,
    'special_zips' => ['77520' => 160, '77521' => 160, '77573' => 160, '77381' => 120, '77598' => 120, '77346' => 125, '77433' => 40, '77447' => 50, '77355' => 80, '77380' => 100, '77013' => 100],
];
