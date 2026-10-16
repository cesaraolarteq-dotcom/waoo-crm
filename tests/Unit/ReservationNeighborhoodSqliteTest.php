<?php

namespace Tests\Unit;

use App\Models\{Event, ResourceReservation};
use App\Services\Scheduling\ReservationNeighborhood;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class ReservationNeighborhoodSqliteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('events', function (Blueprint $t) {
            $t->id(); $t->dateTime('starts_at'); $t->dateTime('ends_at'); $t->unsignedInteger('setup_minutes')->nullable(); $t->unsignedInteger('breakdown_minutes')->nullable(); $t->timestamps();
        });
        Schema::create('resource_reservations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('event_id'); $t->unsignedBigInteger('resource_id'); $t->dateTime('starts_at'); $t->dateTime('ends_at'); $t->timestamps();
            $t->index(['resource_id', 'starts_at', 'ends_at']);
        });
    }

    private function event(string $start, ?string $end = null): Event
    {
        $start = CarbonImmutable::parse($start)->utc();
        return Event::create(['starts_at' => $start, 'ends_at' => $end ? CarbonImmutable::parse($end)->utc() : $start->addHour(), 'setup_minutes' => 10, 'breakdown_minutes' => 10])->fresh();
    }

    private function reserve(Event $e, int $resource = 1): void
    {
        ResourceReservation::create(['event_id' => $e->id, 'resource_id' => $resource, 'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at]);
    }

    public function test_sqlite_keeps_distant_neighbors_and_ignores_an_unrelated_resource(): void
    {
        $candidate = $this->event('2027-07-01T15:00:00-05:00');
        $before = $this->event('2027-06-01T15:00:00-05:00'); $this->reserve($before);
        $after = $this->event('2027-08-01T15:00:00-05:00'); $this->reserve($after);
        $this->reserve($this->event('2027-07-01T15:00:00-05:00'), 2);
        $r = app(ReservationNeighborhood::class)->forResource($candidate, 1);
        $this->assertFalse($r['overlaps']); $this->assertSame($before->id, $r['before']->id); $this->assertSame($after->id, $r['after']->id);
    }

    public function test_sqlite_detects_midnight_setup_overlap_and_a_long_event(): void
    {
        $candidate = $this->event('2027-07-01T00:05:00-05:00');
        $this->reserve($this->event('2027-06-30T22:00:00-05:00', '2027-06-30T23:50:00-05:00'));
        $this->assertTrue(app(ReservationNeighborhood::class)->forResource($candidate, 1)['overlaps']);
        $this->reserve($this->event('2027-06-01T00:00:00-05:00', '2027-07-02T00:00:00-05:00'), 2);
        $this->assertTrue(app(ReservationNeighborhood::class)->forResource($candidate, 2)['overlaps']);
    }

    public function test_sqlite_preserves_exact_half_open_boundaries(): void
    {
        $candidate = $this->event('2027-07-01T15:00:00-05:00');
        $before = $this->event('2027-07-01T13:00:00-05:00', '2027-07-01T14:40:00-05:00'); $this->reserve($before);
        $after = $this->event('2027-07-01T16:20:00-05:00'); $this->reserve($after);
        $r = app(ReservationNeighborhood::class)->forResource($candidate, 1);
        $this->assertFalse($r['overlaps']); $this->assertSame($before->id, $r['before']->id); $this->assertSame($after->id, $r['after']->id);
    }
}
