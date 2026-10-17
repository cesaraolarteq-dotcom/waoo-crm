<?php
namespace App\Services\Routing;

use Illuminate\Support\Facades\Http;

final class GoogleTravelRoute
{
    public function forAddress(string $address): ?array
    {
        $cfg = TravelFeeRules::rules();
        try {
            $response = Http::connectTimeout(3)->timeout(10)->withHeaders(['X-Goog-Api-Key' => config('waoo.google_routes_key'),
                'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.travelAdvisory.tollInfo'])->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                'origin' => ['address' => $cfg['origin_zip'].', TX, USA'], 'destination' => ['address' => $address], 'travelMode' => 'DRIVE',
                'routingPreference' => 'TRAFFIC_UNAWARE', 'computeAlternativeRoutes' => true, 'extraComputations' => ['TOLLS'],
                'routeModifiers' => ['avoidTolls' => false, 'tollPasses' => [$cfg['toll_pass']]], 'units' => 'IMPERIAL']);
            if (!$response->successful()) return null;
            $routes = $response->json('routes');
            if (!is_array($routes) || !$routes) return null;
            foreach ($routes as $route) {
                if (!is_array($route) || !isset($route['distanceMeters'],$route['duration']) || !is_int($route['distanceMeters']) || $route['distanceMeters'] < 0
                    || !is_string($route['duration']) || !preg_match('/^\d+(?:\.\d+)?s$/D', $route['duration'])) return null;
            }
            $best = $routes[0]; $pick = $best['distanceMeters'] * 2 / 1609.344 < $cfg['best_route_under_round_trip_miles'] ? $best : null;
            foreach ($pick === null ? $routes : [] as $route) {
                if (!isset($route['travelAdvisory']['tollInfo'])) continue;
                if ($pick === null || (int) $route['duration'] < (int) $pick['duration']) $pick = $route;
            }
            if ($pick === null) foreach ($routes as $route) if ($pick === null || (int) $route['duration'] < (int) $pick['duration']) $pick = $route;
            $toll = 0.0;
            if (isset($pick['travelAdvisory']['tollInfo'])) {
                $prices = $pick['travelAdvisory']['tollInfo']['estimatedPrice'] ?? [];
                if (!$prices) $toll = (float) $cfg['default_one_way_toll'];
                else foreach ($prices as $price) {
                    if (($price['currencyCode'] ?? null) !== 'USD') return null;
                    $units = $price['units'] ?? 0; $nanos = $price['nanos'] ?? 0;
                    if ((!is_int($units) && !is_string($units)) || !ctype_digit((string) $units) || (float) $units > 1000000
                        || !is_int($nanos) || $nanos < 0 || $nanos > 999999999) return null;
                    $toll += (int) $units + $nanos / 1e9;
                }
            }
            if ($toll < 0 || !is_finite($toll)) return null;
            return ['miles' => $pick['distanceMeters'] / 1609.344, 'toll_cents' => (int) round(round($toll, 2) * 100),
                'route_type' => $best['distanceMeters'] * 2 / 1609.344 < $cfg['best_route_under_round_trip_miles'] ? 'google_best' : (isset($pick['travelAdvisory']['tollInfo']) ? 'fastest_toll' : 'fastest')];
        } catch (\Throwable) { return null; } // Never log URL headers/key or personal address/provider payload.
    }
}
