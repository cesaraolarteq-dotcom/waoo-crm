<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Routing\TravelFeeQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TravelFeeReferenceCalibrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_22_recorded_reference_cases_match_with_the_recorded_route_selection(): void
    {
        config(['waoo.sandbox_routes' => true, 'waoo.google_routes_key' => 'fictional-calibration-key']);
        Http::preventStrayRequests();
        $cases = json_decode(file_get_contents(base_path('docs/calibration/ROUTE-01-reference-22.json')), true, flags: JSON_THROW_ON_ERROR)['cases'];
        $this->assertCount(22, $cases);
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);

        $sequence = Http::sequence();
        foreach ($cases as $case) {
            if ($case['rule'] === 'special_zip') continue;
            $routes = [];
            foreach ($case['routes_measured_2026_10_14'] as $route) {
                // The reference retained tenths of miles/minutes, not Google's original exact meters/seconds.
                $r = ['distanceMeters' => (int) round($route['one_way_miles'] * 1609.344),
                    'duration' => (string) ($route['minutes'] * 60).'s'];
                if ($route['one_way_toll_usd'] !== null) {
                    $cents = (int) round($route['one_way_toll_usd'] * 100);
                    $r['travelAdvisory']['tollInfo']['estimatedPrice'] = [['currencyCode' => 'USD',
                        'units' => (string) intdiv($cents, 100), 'nanos' => ($cents % 100) * 10000000]];
                }
                $routes[] = $r;
            }
            $sequence->push(['routes' => $routes]);
        }
        Http::fake(['https://routes.googleapis.com/directions/v2:computeRoutes' => $sequence]);
        foreach ($cases as $case) {
            $q = app(TravelFeeQuote::class)->forAddress($owner, '123 Fictional Calibration Venue, TX '.$case['zip'], $case['zip']);
            $this->assertSame('ok', $q['status'], $case['zip']);
            $this->assertSame((int) ($case['expected_fee_usd'] * 100), $q['fee_cents'], $case['zip']);
            $this->assertSame($case['rule'] === 'special_zip', $q['breakdown']['special_zip'], $case['zip']);
            if ($case['rule'] === 'special_zip') continue;
            $selected = collect($case['routes_measured_2026_10_14'])->firstWhere('google_label', $case['selected']);
            $this->assertNotNull($selected, $case['zip']);
            $this->assertEqualsWithDelta($selected['one_way_miles'], $q['breakdown']['one_way_miles'], 1 / 1609.344, $case['zip']);
            $this->assertSame((int) round(($selected['one_way_toll_usd'] ?? 0) * 200), $q['breakdown']['round_trip_tolls_cents'], $case['zip']);
        }
        Http::assertSentCount(count(array_filter($cases, fn ($c) => $c['rule'] === 'formula')));
    }
}
