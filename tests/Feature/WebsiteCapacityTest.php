<?php
namespace Tests\Feature;

use App\Models\{Character, Customer, Event, EventService, ImportedProjectLink, PriceVersion, Resource, ServiceVariant, User};
use App\Services\Availability\{CheckWebsiteCandidates, WebsiteCapacity};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Owner rules 2026-10-29: 4 parties at once (4 speakers), 10 per day, 24 h, setup + party + breakdown + 30 min,
// costumes/equipment physical (unavailable), people short (driver or skill) = owner approval; HoneyBook via Google counts.
class WebsiteCapacityTest extends TestCase
{
    use DatabaseMigrations;
    private ServiceVariant $magic; private Character $elsa; private int $n = 0; private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-01T09:00:00-06:00'));
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true]);
        $this->elsa = Character::create(['name' => 'Elsa', 'active' => true]);
        Resource::create(['code' => 'ELSA-1', 'name' => 'Elsa 1', 'kind' => 'costume', 'status' => 'active', 'character_id' => $this->elsa->id, 'skills' => []]);
        for ($i = 1; $i <= 2; $i++) Resource::create(['code' => 'DRV-'.$i, 'name' => 'Staff '.$i, 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer', 'driver']]);
        $this->magic = ServiceVariant::create(['name' => 'Mágico', 'status' => 'active', 'duration_minutes' => 60, 'setup_minutes' => 15, 'breakdown_minutes' => 15,
            'character_quantity' => 1, 'requirements' => [], 'character_options' => [['character_id' => $this->elsa->id, 'requirements' => [
                ['kind' => 'costume', 'character_id' => $this->elsa->id, 'quantity' => 1], ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]]]);
        $this->priceId = PriceVersion::create(['service_variant_id' => $this->magic->id, 'amount_cents' => 37500, 'currency' => 'USD', 'created_by' => $owner->id])->id;
    }

    private function party(string $start, bool $elsa = false, string $status = 'capacity_reserved', bool $honeybook = false, ?int $setup = 15): Event
    {
        $s = CarbonImmutable::parse($start)->utc();
        $c = Customer::create(['name' => 'María González '.(++$this->n), 'locale' => 'es']);
        $e = Event::create(['customer_id' => $c->id, 'title' => 'Fiesta '.$this->n, 'starts_at' => $s, 'ends_at' => $s->addHour(), 'timezone' => 'America/Chicago', 'setup_minutes' => $setup, 'breakdown_minutes' => $setup]);
        $e->status = $status; $e->save();
        EventService::create(['event_id' => $e->id, 'service_variant_id' => $this->magic->id, 'price_version_id' => $this->priceId, 'operational_ready' => true, 'duration_minutes' => 60,
            'requirements_snapshot' => $elsa ? [['kind' => 'costume', 'character_id' => $this->elsa->id, 'quantity' => 1]] : []]);
        if ($honeybook) DB::table('imported_project_links')->insert(['event_id' => $e->id, 'customer_id' => $c->id, 'project_key' => hash('sha256', 'hb-'.$this->n), 'current_revision_id' => null,
            'domain_sha256' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        return $e;
    }

    private function check(array $times, array $characters = null, ?int $variant = null): array
    {
        return app(CheckWebsiteCandidates::class)->execute(['date' => '2026-11-21', 'timezone' => 'America/Chicago', 'service_variant_id' => $variant ?? $this->magic->id,
            'characters' => $characters ?? [['character_id' => $this->elsa->id, 'quantity' => 1, 'outfit' => null]],
            'candidate_starts' => array_map(fn ($t) => ['local' => '2026-11-21T'.$t, 'offset_choice' => null], $times)]);
    }

    public function test_free_day_offers_the_times_and_a_booked_costume_blocks_overlapping_ones_only(): void
    {
        $r = $this->check(['14:00']);
        $this->assertSame(['available'], array_column($r['slots'], 'state'));
        $this->party('2026-11-21T14:00:00-06:00', elsa: true);
        $r = $this->check(['14:00', '15:30', '16:15', '17:30']);
        // 14:00 same time; 15:30 inside 14:00 party + 15 breakdown + 30 gap; 16:15 fits but the drive from the 14:00 party
        // is not proven, so the owner confirms it; 17:30 is clear of it.
        $this->assertSame([['candidate' => 0, 'code' => 'unavailable'], ['candidate' => 1, 'code' => 'unavailable']], $r['unresolved']);
        $this->assertSame(['owner_approval_required', 'available'], array_column($r['slots'], 'state'));
        $this->assertSame('available', $r['capacity_state']);
        $this->assertFalse($r['reservation_created']);
    }

    public function test_four_parties_at_once_is_the_limit_and_people_short_becomes_owner_approval(): void
    {
        // 2 drivers: a 3rd party at the same time is still possible but needs the owner (call someone in).
        $this->party('2026-11-21T10:00:00-06:00'); $this->party('2026-11-21T10:00:00-06:00');
        $this->assertSame('owner_approval_required', $this->check(['10:00'])['slots'][0]['state']);
        // 4 parties at once (4 speakers): the 5th is unavailable whoever is free.
        $this->party('2026-11-21T10:00:00-06:00'); $this->party('2026-11-21T10:00:00-06:00');
        $this->assertSame('unavailable', $this->check(['10:00'])['unresolved'][0]['code']);
    }

    public function test_ten_parties_fill_the_day_and_cancelled_or_unbooked_drafts_never_block(): void
    {
        $this->party('2026-11-21T12:00:00-06:00', status: 'cancelled');
        $this->party('2026-11-21T12:00:00-06:00', status: 'draft');
        $this->assertSame('available', $this->check(['12:00'])['slots'][0]['state']);
        for ($i = 0; $i < 10; $i++) $this->party(sprintf('2026-11-21T%02d:00:00-06:00', $i * 2), honeybook: true, status: 'draft');
        $r = $this->check(['21:00']);
        $this->assertSame([], $r['slots']);
        $this->assertSame('full', $r['level']);
        $this->assertSame('unavailable', $r['capacity_state']);
    }

    public function test_short_notice_and_incomplete_choices_are_never_offered_as_instant(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15T09:00:00-06:00'));
        $this->assertTrue($this->check(['14:00'])['slots'][0]['requires_owner_approval']);
        $r = $this->check(['14:00'], []);
        $this->assertSame([['candidate' => 0, 'code' => 'incomplete_request']], $r['unresolved']);
        $this->expectException(ValidationException::class);
        $this->check(['14:00', '14:00']);
    }

    public function test_google_busy_counts_once_and_a_google_failure_is_never_free(): void
    {
        $this->party('2026-11-21T10:00:00-06:00', honeybook: true, status: 'draft');
        $busy = [[CarbonImmutable::parse('2026-11-21T10:00:00-06:00'), CarbonImmutable::parse('2026-11-21T11:00:00-06:00')],
            [CarbonImmutable::parse('2026-11-21T10:05:00-06:00'), CarbonImmutable::parse('2026-11-21T11:00:00-06:00')]];
        $day = CarbonImmutable::parse('2026-11-21T00:00:00-06:00');
        $this->assertSame(1, (new WebsiteCapacity($day, [$busy[0]]))->partiesOn('2026-11-21'), 'The calendar entry of an imported party counts once.');
        $this->assertSame(2, (new WebsiteCapacity($day, $busy))->partiesOn('2026-11-21'), 'Two entries starting together are two parties: one is absorbed, one stays.');
        $this->app->instance('waoo.availability.busy', fn () => null);
        $r = $this->check(['14:00']);
        $this->assertSame([], $r['slots']);
        $this->assertSame('evaluation_unavailable', $r['unresolved'][0]['code']);
    }

    public function test_honeybook_calendar_feed_times_are_read_in_houston_and_utc(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART:20261122T003000Z\r\nDTEND:20261122T023000Z\r\nSUMMARY:x\r\nEND:VEVENT\r\n"
            ."BEGIN:VEVENT\r\nDTSTART;TZID=America/Chicago:20261121T140000\r\nDTEND;TZID=America/Chicago:20261121T160000\r\nEND:VEVENT\r\n"
            ."BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261123\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nSTATUS:CANCELLED\r\nDTSTART:20261121T200000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $e = \App\Services\Availability\HoneyBookCalendarFeed::events($ics);
        $this->assertCount(3, $e);
        $this->assertSame('2026-11-21T18:30:00-06:00', $e[0][0]->setTimezone('America/Chicago')->toIso8601String(), 'A UTC evening party stays on the Houston Saturday.');
        $this->assertSame('2026-11-21T14:00:00-06:00', $e[1][0]->toIso8601String());
        $this->assertSame('2026-11-24T00:00:00-06:00', $e[2][1]->toIso8601String(), 'All-day entry lasts the whole day.');
        $cap = new WebsiteCapacity(CarbonImmutable::parse('2026-11-21T00:00:00-06:00'), array_slice($e, 0, 2));
        $this->assertSame(2, $cap->partiesOn('2026-11-21'));
    }

    private function staffOnly(int $n): void
    {
        DB::table('resources')->where('kind', 'staff')->delete();
        for ($i = 1; $i <= $n; $i++) Resource::create(['code' => 'ONE-'.$i, 'name' => 'Staff '.$i, 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer', 'driver']]);
    }

    public function test_one_multiskilled_person_is_never_counted_for_two_roles(): void
    {
        // Mágico needs a performer and a driver: with one person who can do both, the party still needs two people.
        $this->staffOnly(1);
        $this->assertSame('owner_approval_required', $this->check(['14:00'])['slots'][0]['state']);
        $this->staffOnly(2);
        $this->assertSame('available', $this->check(['14:00'])['slots'][0]['state']);
    }

    public function test_blocked_sole_equipment_and_unit_holds_are_busy(): void
    {
        $foam = ServiceVariant::create(['name' => 'Cañón de espuma', 'status' => 'active', 'duration_minutes' => 60, 'setup_minutes' => 30, 'breakdown_minutes' => 20,
            'requirements' => [['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'foam']]]);
        $unit = Resource::create(['code' => 'FOAM-1', 'name' => 'Cañón', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'foam', 'skills' => []]);
        $this->assertSame('available', $this->check(['14:00'], [], $foam->id)['slots'][0]['state']);
        DB::table('resource_blocks')->insert(['resource_id' => $unit->id, 'starts_at' => '2026-11-21 18:00:00', 'ends_at' => '2026-11-21 23:00:00', 'reason' => 'Mantenimiento', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('unavailable', $this->check(['14:00'], [], $foam->id)['unresolved'][0]['code'], 'A blocked sole unit is unavailable.');
        DB::table('resource_blocks')->update(['released_at' => now()]);
        // An unbooked draft that already holds the unit (reservation) still blocks it.
        $draft = $this->party('2026-11-21T14:00:00-06:00', status: 'draft');
        DB::table('event_services')->where('event_id', $draft->id)->update(['requirements_snapshot' => json_encode([['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'foam']])]);
        DB::table('resource_reservations')->insert(['event_id' => $draft->id, 'resource_id' => $unit->id, 'starts_at' => '2026-11-21 19:45:00', 'ends_at' => '2026-11-21 21:15:00', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('unavailable', $this->check(['14:00'], [], $foam->id)['unresolved'][0]['code']);
    }

    public function test_outfit_unknown_timing_and_unreadable_calendar_are_never_instant(): void
    {
        $r = $this->check(['14:00'], [['character_id' => $this->elsa->id, 'quantity' => 1, 'outfit' => 'Gala']]);
        $this->assertSame([['candidate' => 0, 'code' => 'review_required']], $r['unresolved'], 'A specific outfit is confirmed by the owner.');
        $this->party('2026-11-21T10:00:00-06:00', setup: null);
        $this->assertSame('owner_approval_required', $this->check(['11:30'])['slots'][0]['state'], 'Unknown setup/breakdown next to the start: owner checks.');
        foreach (["BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:20261121T200000Z\nRRULE:FREQ=WEEKLY\nDTEND:20261121T210000Z\nEND:VEVENT\nEND:VCALENDAR\n",
            "BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:20261121T200000Z\nEND:VEVENT\nEND:VCALENDAR\n",
            "BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:2026-11-21\nDTEND:20261121T210000Z\nEND:VEVENT\nEND:VCALENDAR\n",
            "BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:20261121T200000Z\nDTEND:20261121T210000Z\n"] as $bad)
            $this->assertNull(\App\Services\Availability\HoneyBookCalendarFeed::events($bad));
    }

    public function test_an_active_stripe_hold_on_an_unbooked_draft_blocks_its_costume(): void
    {
        $draft = $this->party('2026-11-21T14:00:00-06:00', elsa: true, status: 'draft');
        $this->assertSame('available', $this->check(['14:00'])['slots'][0]['state'], 'A plain draft holds nothing.');
        $owner = User::first();
        $rev = \App\Models\FinancialRevision::create(['event_id' => $draft->id, 'revision' => 1, 'event_version' => 1, 'currency' => 'USD', 'lines_snapshot' => [],
            'subtotal_cents' => 37500, 'travel_fee_cents' => 0, 'tax_cents' => 0, 'customer_fee_cents' => 0, 'total_cents' => 37500,
            'deposit_target_cents' => 18750, 'balance_due_at' => now()->addDays(19), 'created_by' => $owner->id]);
        DB::table('quick_stripe_leases')->insert(['event_id' => $draft->id, 'customer_id' => $draft->customer_id, 'financial_revision_id' => $rev->id, 'version' => 1,
            'state' => 'active', 'expires_at' => now()->addMinutes(30), 'payload' => '{}', 'payload_sha256' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('unavailable', $this->check(['14:00'])['unresolved'][0]['code'], 'While the client is paying, the costume is held.');
    }
}
