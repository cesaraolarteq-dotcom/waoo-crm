<?php

namespace Tests\Feature;

use App\Models\{Customer, Event, EventService, PriceVersion, Resource, ResourceReservation, ServiceVariant, User};
use App\Services\Scheduling\ReservationNeighborhood;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationNeighborhoodTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        $this->customer = Customer::create(['name' => 'Fixture scheduling client', 'locale' => 'es']);
    }

    private function event(CarbonImmutable $start, ?CarbonImmutable $end = null, int $setup = 10, int $breakdown = 10): Event
    {
        return Event::create(['customer_id' => $this->customer->id, 'title' => 'Fixture schedule', 'address' => 'Fixture venue',
            'starts_at' => $start->utc(), 'ends_at' => ($end ?? $start->addHour())->utc(), 'timezone' => 'America/Chicago',
            'setup_minutes' => $setup, 'breakdown_minutes' => $breakdown, 'travel_fee_cents' => 0])->fresh();
    }

    private function resource(): Resource
    {
        return Resource::create(['code' => 'FIX-'.Str::uuid(), 'name' => 'Fixture speaker', 'kind' => 'equipment',
            'status' => 'active', 'skills' => [], 'equipment_kind' => 'speaker']);
    }

    private function reserve(Event $event, Resource $resource): void
    {
        $event->forceFill(['status' => 'capacity_reserved', 'version' => 2])->save();
        ResourceReservation::create(['event_id' => $event->id, 'resource_id' => $resource->id, 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at]);
    }

    private function shape(array $result): array
    {
        return [$result['overlaps'], $result['before']?->id, $result['after']?->id];
    }

    /** The previous implementation, kept as an independent decision oracle for fictional schedules. */
    private function oldScan(Event $candidate, int $resourceId): array
    {
        $memory = memory_get_usage();
        $reservations = ResourceReservation::where('resource_id', $resourceId)->with('event')->orderBy('starts_at')->get();
        $loadedBytes = max(0, memory_get_usage() - $memory);
        $before = $after = null;
        foreach ($reservations as $reservation) {
            $other = $reservation->event;
            if ($other->starts_at->subMinutes($other->setup_minutes)->lessThan($candidate->ends_at->addMinutes($candidate->breakdown_minutes))
                && $other->ends_at->addMinutes($other->breakdown_minutes)->greaterThan($candidate->starts_at->subMinutes($candidate->setup_minutes))) {
                return ['shape' => [true, null, null], 'loaded_bytes' => $loadedBytes];
            }
            if ($other->ends_at->lessThanOrEqualTo($candidate->starts_at)) $before = $other;
            if ($after === null && $other->starts_at->greaterThanOrEqualTo($candidate->ends_at)) $after = $other;
        }
        return ['shape' => [false, $before?->id, $after?->id], 'loaded_bytes' => $loadedBytes];
    }

    public function test_a_long_event_outside_any_date_window_is_still_a_conflict(): void
    {
        $candidate = $this->event(CarbonImmutable::parse('2027-07-01T15:00:00-05:00'));
        $resource = $this->resource();
        $this->reserve($this->event($candidate->starts_at->subDays(45), $candidate->ends_at->addHour()), $resource);
        $expected = $this->oldScan($candidate, $resource->id)['shape'];
        $events = 0; Event::retrieved(function () use (&$events) { $events++; });
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame($expected, $this->shape(app(ReservationNeighborhood::class)->forResource($candidate, $resource->id)));
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(0, $events, 'Overlap existence must not hydrate the entire conflicting history.');
    }

    public function test_setup_crossing_midnight_uses_busy_intervals_not_only_event_times(): void
    {
        $candidate = $this->event(CarbonImmutable::parse('2027-07-01T00:05:00-05:00'));
        $resource = $this->resource();
        $other = $this->event($candidate->starts_at->subHours(2), $candidate->starts_at->subMinutes(15), 10, 10);
        $this->reserve($other, $resource);
        $this->assertSame([true, null, null], $this->shape(app(ReservationNeighborhood::class)->forResource($candidate, $resource->id)));
        $this->assertSame($this->oldScan($candidate, $resource->id)['shape'], $this->shape(app(ReservationNeighborhood::class)->forResource($candidate, $resource->id)));
    }

    public function test_exact_busy_boundaries_remain_half_open_and_unrelated_resources_are_ignored(): void
    {
        $candidate = $this->event(CarbonImmutable::parse('2027-07-01T15:00:00-05:00'));
        $resource = $this->resource();
        $before = $this->event($candidate->starts_at->subHours(2), $candidate->starts_at->subMinutes(20));
        $after = $this->event($candidate->ends_at->addMinutes(20));
        $this->reserve($before, $resource); $this->reserve($after, $resource);
        $this->reserve($this->event($candidate->starts_at), $this->resource());
        $this->assertSame([false, $before->id, $after->id], $this->shape(app(ReservationNeighborhood::class)->forResource($candidate, $resource->id)));
        $this->assertSame($this->oldScan($candidate, $resource->id)['shape'], $this->shape(app(ReservationNeighborhood::class)->forResource($candidate, $resource->id)));
    }

    public function test_large_history_hydrates_two_neighbors_instead_of_all_reservations_and_events(): void
    {
        $candidate = $this->event(CarbonImmutable::parse('2027-07-01T15:00:00-05:00'));
        $resource = $this->resource();
        $after = $this->event($candidate->starts_at->addDays(20)); $this->reserve($after, $resource);
        $events = $reservations = [];
        for ($i = 0; $i < 1200; $i++) {
            $start = $candidate->starts_at->subDays(20 + (1199 - $i) * 2);
            $events[] = ['id' => 10000 + $i, 'customer_id' => $this->customer->id, 'title' => 'Fixture historic event', 'status' => 'capacity_reserved',
                'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $start->addHour()->format('Y-m-d H:i:s'), 'timezone' => 'America/Chicago',
                'address' => 'Fixture historic venue', 'setup_minutes' => 10, 'breakdown_minutes' => 10, 'travel_fee_cents' => 0, 'version' => 2];
            $reservations[] = ['event_id' => 10000 + $i, 'resource_id' => $resource->id, 'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $start->addHour()->format('Y-m-d H:i:s')];
        }
        foreach (array_chunk($events, 200) as $chunk) DB::table('events')->insert($chunk);
        foreach (array_chunk($reservations, 200) as $chunk) DB::table('resource_reservations')->insert($chunk);
        unset($events, $reservations);

        $eventModels = $reservationModels = 0;
        Event::retrieved(function () use (&$eventModels) { $eventModels++; });
        ResourceReservation::retrieved(function () use (&$reservationModels) { $reservationModels++; });
        DB::enableQueryLog(); DB::flushQueryLog(); $started = hrtime(true);
        $legacy = $this->oldScan($candidate, $resource->id);
        $old = ['event_models' => $eventModels, 'reservation_models' => $reservationModels, 'queries' => count(DB::getQueryLog()),
            'loaded_bytes' => $legacy['loaded_bytes'], 'elapsed_ms' => (hrtime(true) - $started) / 1e6];
        $eventModels = $reservationModels = 0; DB::flushQueryLog(); $started = hrtime(true); $memory = memory_get_usage();
        $result = app(ReservationNeighborhood::class)->forResource($candidate, $resource->id);
        $queries = DB::getQueryLog();
        $bounded = ['event_models' => $eventModels, 'reservation_models' => $reservationModels, 'queries' => count($queries),
            'loaded_bytes' => max(0, memory_get_usage() - $memory), 'elapsed_ms' => (hrtime(true) - $started) / 1e6];
        DB::disableQueryLog(); DB::flushQueryLog();
        $this->assertSame($legacy['shape'], $this->shape($result));
        $this->assertSame([false, 11199, $after->id], $this->shape($result), 'Neighbors twenty days away must not disappear.');
        $this->assertSame(1201, $old['event_models']); $this->assertSame(1201, $old['reservation_models']);
        $this->assertSame(2, $old['queries']); $this->assertSame(3, $bounded['queries']);
        $this->assertSame(2, $bounded['event_models']); $this->assertSame(0, $bounded['reservation_models']);
        $plans = [];
        foreach ($queries as $query) {
            $plan = DB::select('EXPLAIN '.$query['query'], $query['bindings']);
            $plans[] = array_map(fn ($row) => array_intersect_key((array) $row, array_flip(['table', 'type', 'key', 'rows', 'Extra'])), $plan);
        }
        $dir = storage_path('framework/testing'); if (!is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($dir.'/reservation-neighborhood-benchmark.json', json_encode(['fixture_reservations' => 1201, 'legacy' => $old, 'bounded' => $bounded, 'explain' => $plans], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function transfer(Event $from, Event $to): array
    {
        return ['from_event_id' => $from->id, 'to_event_id' => $to->id, 'from_version' => $from->version, 'to_version' => $to->version,
            'travel_minutes' => 10, 'contingency_minutes' => 10, 'departure_at' => $from->ends_at->addMinutes($from->breakdown_minutes)->toIso8601String(),
            'note' => 'Fixture traffic reviewed for the actual party date and departure.'];
    }

    public function test_confirmation_still_requires_both_current_transfer_reviews_and_retries_once(): void
    {
        $candidate = $this->event(CarbonImmutable::parse('2027-07-01T15:00:00-05:00'));
        $resource = $this->resource();
        $before = $this->event($candidate->starts_at->subDays(20)); $this->reserve($before, $resource);
        $after = $this->event($candidate->starts_at->addDays(20)); $this->reserve($after, $resource);
        $variant = ServiceVariant::create(['name' => 'Fixture schedule package', 'status' => 'active', 'duration_minutes' => 60,
            'requirements' => [['kind' => 'equipment', 'equipment_kind' => 'speaker', 'quantity' => 1]]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 20000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        EventService::create(['event_id' => $candidate->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id,
            'requirements_snapshot' => $variant->requirements, 'duration_minutes' => 60, 'operational_ready' => true]);
        $input = ['expected_version' => 1, 'resource_ids' => [$resource->id], 'payment' => ['method' => 'external_zelle', 'reference' => 'FIXTURE-SCHEDULE-RECEIPT', 'amount_cents' => 10000, 'received_at' => now()->subHour()->toIso8601String()],
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Fixture crew, cargo and return plan checked.', 'transfers' => []]];
        $url = '/admin/api/events/'.$candidate->id.'/confirm-capacity';
        $this->postJson($url, $input)->assertUnprocessable();
        $input['review']['transfers'] = [$this->transfer($before, $candidate)];
        $this->postJson($url, $input)->assertUnprocessable();
        $input['review']['transfers'][] = $this->transfer($candidate, $after);
        $input['review']['transfers'][0]['from_version'] = 1;
        $this->postJson($url, $input)->assertUnprocessable();
        $this->assertDatabaseCount('booking_evidence', 0);
        $this->assertDatabaseCount('operational_reviews', 0);
        $input['review']['transfers'][0]['from_version'] = $before->version;
        $this->postJson($url, $input)->assertOk()->assertJsonPath('status', 'capacity_reserved');
        $this->postJson($url, $input)->assertOk();
        $this->assertDatabaseCount('booking_evidence', 1);
        $this->assertDatabaseCount('operational_reviews', 1);
        $this->assertDatabaseCount('resource_reservations', 3);
    }
}
