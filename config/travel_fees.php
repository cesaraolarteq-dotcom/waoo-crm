<?php
// Owner-approved website data (2026-10-29: departure point 23645 Katy Fwy, 77447 fixed $50), waoo-website5afc001 / unchanged083563b; no credentials.
return [
    'version' => 'website-2026-10-29-katy-fwy', 'origin_address' => '23645 Katy Fwy, Katy, TX 77494', 'origin_zip' => '77494', 'toll_pass' => 'US_TX_EZTAG',
    'best_route_under_round_trip_miles' => 30, 'flat_up_to_miles' => 10, 'flat_fee' => 20,
    'tiers' => [['up_to' => 40, 'rate' => 0.35], ['up_to' => 55, 'rate' => 2.00], ['up_to' => null, 'rate' => 1.40]],
    'default_one_way_toll' => 5, 'round_to' => 5, 'max_round_trip_miles' => 160, 'cache_days' => 30, 'google_calls_per_day' => 75,
    'special_zips' => ['77520' => 180, '77521' => 180, '77433' => 40, '77447' => 50, '77355' => 80, '77380' => 100, '77013' => 100, '77573' => 125],
];
