<?php

namespace App\Services\Routing;

use Illuminate\Support\Facades\DB;

final class TravelRouteBudget
{
    /** One shared allowance for fee quotes and travel time requests, based on Houston's date. */
    public static function take(): bool
    {
        $limit = (int) TravelFeeRules::rules()['google_calls_per_day'];
        if ($limit < 1) return false;
        $day = now('America/Chicago')->toDateString();

        return DB::transaction(function () use ($day, $limit) {
            DB::table('travel_route_daily_usage')->insertOrIgnore(['day' => $day, 'calls' => 0]);

            return DB::table('travel_route_daily_usage')->where('day', $day)
                ->where('calls', '<', $limit)->increment('calls') === 1;
        }, 3);
    }
}
