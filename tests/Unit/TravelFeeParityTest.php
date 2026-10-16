<?php
namespace Tests\Unit;

use App\Services\Routing\TravelFeeRules;
use Tests\TestCase;

class TravelFeeParityTest extends TestCase
{
    public function test_formula_matches_exact_website_function_at_tier_rounding_and_fractional_boundaries(): void
    {
        require_once __DIR__.'/../Fixtures/WebsiteTravelFee.php';
        $cfg = config('travel_fees');
        // Synthetic differential inputs, NOT the missing22 real calibrated ZIP cases.
        foreach ([0,9.999,10,10.001,14.99,15,39.999,40,40.001,54.999,55,55.001,79.999,80,80.001] as $miles) {
            foreach ([0,1,249,250,251,500,1234] as $tollCents) {
                $website = fixture_website_travel_fee($miles, $tollCents / 100, $cfg);
                $this->assertSame($website['fee'] * 100, TravelFeeRules::calculate($miles, $tollCents)['fee_cents']);
            }
        }
    }
}
