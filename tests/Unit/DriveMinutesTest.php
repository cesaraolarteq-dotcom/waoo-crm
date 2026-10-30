<?php
namespace Tests\Unit;

use App\Services\Routing\DriveMinutes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DriveMinutesTest extends TestCase
{
    public function test_google_duration_plus_zip_slack_cached_and_one_fetch_per_request(): void
    {
        config(['waoo.google_routes_key' => 'test-key', 'cache.default' => 'array']);
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '1500s']]])]);
        $at = CarbonImmutable::now()->addDays(3);
        $d = new DriveMinutes();
        $this->assertSame(25 + DriveMinutes::ZIP_SLACK_MINUTES, $d->between('77494', '77084', $at));
        $this->assertSame(35, $d->between('77494', '77084', $at), 'cached');
        $this->assertNull($d->between('77494', '77002', $at), 'only one uncached fetch per request');
        $this->assertSame(DriveMinutes::ZIP_SLACK_MINUTES, $d->between('77494', '77494', $at));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['routingPreference'] === 'TRAFFIC_AWARE' && $r['origin']['address'] === '77494, TX, USA');
    }

    public function test_failures_and_bad_input_are_unknown(): void
    {
        config(['waoo.google_routes_key' => 'test-key', 'cache.default' => 'array']);
        Http::fake(['routes.googleapis.com/*' => Http::response(['error' => 'x'], 500)]);
        $at = CarbonImmutable::now()->addDays(3);
        $this->assertNull((new DriveMinutes())->between('77494', '77084', $at));
        $this->assertNull((new DriveMinutes())->between('7749', '77084', $at));
        $this->assertNull((new DriveMinutes())->between('77494', '77084', CarbonImmutable::now()->subHour()), 'past: no traffic estimate');
        config(['waoo.google_routes_key' => null]);
        $this->assertNull((new DriveMinutes())->between('77494', '77085', $at));
    }
    public function test_exact_locations_departure_and_fractional_duration_are_used_without_zip_shortcuts(): void
    {
        config(['waoo.google_routes_key' => 'test-key', 'cache.default' => 'array']);
        Http::fake(['routes.googleapis.com/*' => Http::sequence()
            ->push(['routes' => [['duration' => '60.1s']]])->push(['routes' => [['duration' => '121s']]])]);
        $at = CarbonImmutable::now()->addDays(3)->startOfHour();
        $d = new DriveMinutes();
        $this->assertSame(2, $d->betweenAddresses('100 Example Lane, Katy TX', '200 Example Lane, Katy TX', $at));
        $this->assertSame(2, $d->betweenAddresses('100 Example Lane, Katy TX', '200 Example Lane, Katy TX', $at));
        $this->assertNull($d->betweenAddresses('100 Example Lane, Katy TX', '200 Example Lane, Katy TX', $at->addMinute()));
        $this->assertSame(3, (new DriveMinutes())->betweenAddresses('100 Example Lane, Katy TX', '200 Example Lane, Katy TX', $at->addMinute()));
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['origin']['address'] === '100 Example Lane, Katy TX'
            && $r['destination']['address'] === '200 Example Lane, Katy TX'
            && $r['departureTime'] === $at->utc()->format('Y-m-d\TH:i:s\Z') && $r['routingPreference'] === 'TRAFFIC_AWARE');
    }

    public function test_no_exact_route_is_assumed_for_missing_location_failure_or_database_locks(): void
    {
        config(['waoo.google_routes_key' => 'test-key', 'cache.default' => 'array']);
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => []])]);
        $at = CarbonImmutable::now()->addDays(3);
        $d = new DriveMinutes();
        $this->assertNull($d->betweenAddresses(null, '200 Example Lane', $at));
        $this->assertNull($d->betweenAddresses('100 Example Lane', '200 Example Lane', $at));
        Http::assertSentCount(1);
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $this->assertNull((new DriveMinutes())->betweenAddresses('100 Example Lane', '200 Example Lane', $at));
        } finally { \Illuminate\Support\Facades\DB::rollBack(); }
        Http::assertSentCount(1);
    }

}
