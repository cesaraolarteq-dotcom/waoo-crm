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
}
