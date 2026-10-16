<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, PriceVersion, ServiceVariant, TravelFeeQuoteRecord, User};
use App\Services\Records\SaveEventDraft;
use App\Services\Routing\{TravelFeeQuote, TravelFeeRules};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http};
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class TravelFeeQuotesTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private array $draft;
    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_routes' => true, 'waoo.google_routes_key' => 'fictional-route-key']);
        Http::preventStrayRequests(); $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]); $this->actingAs($this->owner);
        $c = Customer::create(['name' => 'Fixture Route Client', 'locale' => 'es']);
        $v = ServiceVariant::create(['name' => 'Fixture package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $p = PriceVersion::create(['service_variant_id' => $v->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]); $start = now()->addDays(20)->startOfHour();
        $this->draft = ['customer_id' => $c->id, 'title' => 'Fixture party', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addHour()->toIso8601String(), 'timezone' => 'America/Chicago',
            'address' => '123 Fictional Venue, Katy, TX 77493', 'postal_code' => '77493', 'travel_fee_cents' => 2500, 'services' => [['price_version_id' => $p->id]]];
    }
    private function quote(string $address = '123 Fictional Venue, Katy, TX 77493', ?string $zip = '77493'): array
    {
        return app(TravelFeeQuote::class)->forAddress($this->owner, $address, $zip);
    }
    private function route(int $meters = 16093, string $duration = '900s', ?array $tolls = null): array
    {
        return ['distanceMeters' => $meters, 'duration' => $duration, ...($tolls !== null ? ['travelAdvisory' => ['tollInfo' => $tolls]] : [])];
    }
    public function test_short_trip_best_route_long_trip_fastest_toll_and_field_contract(): void
    {
        Http::fakeSequence()->push(['routes' => [$this->route(10000), $this->route(9000, '800s', ['estimatedPrice' => [['currencyCode' => 'USD', 'units' => '2', 'nanos' => 0]]])]])->push(['routes' => [$this->route(40000, '500s'), $this->route(42000, '900s', []), $this->route(43000, '800s', [])]]);
        $this->assertSame('google_best', $this->quote()['breakdown']['route_type']);
        Http::assertSent(fn ($r) => $r['destination']['address'] === $this->draft['address'] && $r['extraComputations'] === ['TOLLS'] && $r['routeModifiers']['tollPasses'] === ['US_TX_EZTAG']);
        $quote = $this->quote('456 Other Fictional Venue, Katy, TX 77493');
        $this->assertSame('fastest_toll', $quote['breakdown']['route_type']);
        $this->assertEqualsWithDelta(43000 / 1609.344, $quote['breakdown']['one_way_miles'], 0.000001);
        $this->assertSame(1000, $quote['breakdown']['round_trip_tolls_cents']);
    }
    public function test_all_seven_fixed_zips_skip_google_even_without_key(): void
    {
        Http::fake(); config(['waoo.google_routes_key' => null]);
        foreach (['77520' => 18000,'77521' => 18000,'77433' => 4000,'77355' => 8000,'77380' => 10000,'77013' => 10000,'77573' => 12500] as $zip => $fee) {
            $q = $this->quote('123 Fictional Venue, TX '.$zip, (string) $zip); $this->assertSame($fee, $q['fee_cents']); $this->assertTrue($q['breakdown']['special_zip']);
        }
        Http::assertNothingSent(); $this->assertDatabaseCount('travel_route_daily_usage', 0);
    }
    public function test_outside_missing_and_mismatched_postal_data_never_become_zero(): void
    {
        Http::fake();
        foreach ([['123 Fictional Venue, NY 10001','10001','outside'],['123 Fictional Venue',null,'review'],['123 Fictional Venue, TX 77493','77433','review']] as [$address,$zip,$status]) {
            $q = $this->quote($address,$zip); $this->assertSame($status, $q['status']); $this->assertNull($q['fee_cents']);
        }
        $q = $this->quote('123 Fictional Venue, TX 77433', null); $this->assertSame(4000, $q['fee_cents']); Http::assertNothingSent();
    }
    public function test_distance_limit_provider_errors_and_malformed_tolls_stay_pending(): void
    {
        Http::fakeSequence()->push(['routes' => [$this->route(130000)]])->push([], 500)->push(['routes' => [$this->route(40000, '900s', ['estimatedPrice' => [['currencyCode' => 'USD','units' => 'invalid']]])]]);
        $q = $this->quote(); $this->assertSame('review', $q['status']); $this->assertNull($q['fee_cents']);
        $q = $this->quote('234 Fictional Venue, Katy, TX 77493'); $this->assertSame('unavailable', $q['status']); $this->assertNull($q['fee_cents']);
        $this->assertSame('unavailable', $this->quote('345 Fictional Venue, Katy, TX 77493')['status']);
    }
    public function test_cache_is_address_and_rules_scoped_and_errors_are_not_cached(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [$this->route()]])]);
        $first = $this->quote(); $same = $this->quote('  123 FICTIONAL   VENUE, KATY, TX 77493  ');
        $this->assertSame($first['quote_id'], $same['quote_id']); $this->assertTrue($same['cached']); Http::assertSentCount(1);
        $this->quote('456 Fictional Venue, Katy, TX 77493'); Http::assertSentCount(2);
        config(['travel_fees.version' => 'fixture-changed-rule-version']); $this->quote(); Http::assertSentCount(3);
        $this->travel(31)->days(); $this->quote(); Http::assertSentCount(4);
    }
    public function test_global_daily_cap_is_atomic_under_competing_owners_and_cache_still_works(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [$this->route()]])]);
        config(['travel_fees.google_calls_per_day' => 1]); $actor = $this->owner;
        $worker = fn ($address) => fn () => app(TravelFeeQuote::class)->forAddress($actor, $address, '77493')['status'];
        $results = ConcurrentWorkers::run([$worker('123 Fictional A Venue, TX 77493'),$worker('456 Fictional B Venue, TX 77493')]);
        $this->assertContains('ok', $results); $this->assertContains('unavailable', $results);
        $this->assertSame(1, (int) DB::table('travel_route_daily_usage')->value('calls'));
        $cached = TravelFeeQuoteRecord::where('status', 'ok')->firstOrFail();
        config(['travel_fees.google_calls_per_day' => 0]);
        $this->assertTrue($this->quote($cached->inputs['address'], $cached->inputs['zip'])['cached']); Http::assertNothingSent();
    }
    public function test_quote_acceptance_is_explicit_and_private_and_manual_override_requires_reason(): void
    {
        $q = $this->quote('123 Fictional Venue, TX 77433', '77433');
        $this->assertDatabaseCount('events', 0);
        $input = [...$this->draft, 'address' => '123 Fictional Venue, TX 77433', 'postal_code' => '77433', 'travel_fee_cents' => 4000, 'travel_fee_quote_id' => $q['quote_id']];
        $event = app(SaveEventDraft::class)->execute($this->owner, $input); $this->assertSame('calculated', $event->travel_fee_evidence['mode']);
        $this->assertArrayNotHasKey('travel_fee_evidence', $event->toArray());
        $this->getJson('/admin/api/events/'.$event->id.'/travel-fee-evidence')->assertOk()->assertJsonPath('evidence.quote_id', $q['quote_id']);
        $this->postJson('/admin/api/events', [...$input, 'travel_fee_cents' => 4500])->assertUnprocessable();
        $edited = app(SaveEventDraft::class)->execute($this->owner, [...$input, 'travel_fee_cents' => 4500, 'travel_fee_override_note' => 'Fixture owner edited fee after review.']);
        $this->assertSame('edited', $edited->travel_fee_evidence['mode']); $this->assertSame(4500, $edited->travel_fee_cents);
    }
    public function test_stale_quote_or_address_cannot_be_accepted_and_prior_evidence_cannot_survive_clearing_address(): void
    {
        $q = $this->quote('123 Fictional Venue, TX 77433', '77433');
        $input = [...$this->draft, 'address' => '123 Fictional Venue, TX 77433', 'postal_code' => '77433', 'travel_fee_cents' => 4000, 'travel_fee_quote_id' => $q['quote_id']];
        $this->postJson('/admin/api/events', [...$input,'address' => '456 Different Fictional Venue, TX 77433'])->assertConflict();
        $event = app(SaveEventDraft::class)->execute($this->owner, $input);
        $this->putJson('/admin/api/events/'.$event->id, [...$this->draft, 'expected_version' => 1, 'address' => null])->assertUnprocessable();
        $manual = app(SaveEventDraft::class)->execute($this->owner, [...$this->draft, 'expected_version' => 1, 'address' => null, 'travel_fee_override_note' => 'Fixture manual review with address pending.'], $event->id);
        $this->assertSame('manual', $manual->travel_fee_evidence['mode']); $this->assertNull($manual->address);
        $this->travel(31)->days(); $this->postJson('/admin/api/events', $input)->assertConflict();
    }
    public function test_owner_2fa_and_feature_gates_protect_direct_and_http_quotes(): void
    {
        Http::fake(); config(['waoo.sandbox_routes' => false]); $this->postJson('/admin/api/travel-fee-quotes', ['address' => $this->draft['address'], 'zip' => '77493'])->assertForbidden();
        config(['waoo.sandbox_routes' => true]); $this->owner->forceFill(['active' => false])->save();
        try { $this->quote(); $this->fail('Inactive owner quoted.'); } catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertTrue(true); }
        $this->owner->forceFill(['active' => true, 'two_factor_confirmed_at' => null])->save(); $this->postJson('/admin/api/travel-fee-quotes', ['address' => $this->draft['address']])->assertForbidden(); Http::assertNothingSent();
    }
    public function test_unavailable_is_not_cached_and_missing_key_does_not_spend_quota(): void
    {
        Http::fakeSequence()->push([],500)->push(['routes' => [$this->route()]]);
        $first = $this->quote(); $second = $this->quote();
        $this->assertSame('unavailable', $first['status']); $this->assertSame('ok', $second['status']); $this->assertFalse($second['cached']);
        Http::assertSentCount(2); config(['waoo.google_routes_key' => null]);
        $q = $this->quote('456 Fixture New Venue, TX 77493');
        $this->assertSame('unavailable', $q['status']); $this->assertNull($q['fee_cents']);
        $this->assertSame(2, (int) DB::table('travel_route_daily_usage')->value('calls'));
        $this->assertStringNotContainsString('fictional-route-key', json_encode(TravelFeeQuoteRecord::all()));
    }

    public function test_daily_quota_rolls_over_at_chicago_midnight(): void
    {
        config(['travel_fees.google_calls_per_day' => 1]);
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [$this->route()]])]);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-02T05:59:00Z'));
        $this->assertSame('ok', $this->quote()['status']); $this->travel(2)->minutes();
        $this->assertSame('ok', $this->quote('456 Fictional Other Venue, TX 77493')['status']);
        $this->assertSame(['2026-11-01','2026-11-02'], DB::table('travel_route_daily_usage')->orderBy('day')->pluck('day')->all());
        Http::assertSentCount(2);
    }

}
