<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Character, Customer, Event, EventService, PriceVersion, Resource, ResourceReservation, ServiceVariant, User};
use App\Services\Availability\AdmitEventCapacity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Admission before money (contract "Admisión atómica antes de pago"): owner paths refuse only what cannot physically happen.
class AdmitEventCapacityTest extends TestCase
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
            'requirements_snapshot' => array_merge([['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']], $elsa ? [['kind' => 'costume', 'character_id' => $this->elsa->id, 'quantity' => 1]] : [])]);
        if ($honeybook) DB::table('imported_project_links')->insert(['event_id' => $e->id, 'customer_id' => $c->id, 'project_key' => hash('sha256', 'hb-'.$this->n), 'current_revision_id' => null,
            'domain_sha256' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        return $e;
    }

    private function admit(Event $e): string
    {
        return DB::transaction(fn () => AdmitEventCapacity::state($e->fresh()));
    }

    public function test_a_party_is_never_counted_against_itself_and_a_fifth_at_once_is_refused(): void
    {
        $first = $this->party('2026-11-21T10:00:00-06:00');
        $this->assertNotSame('unavailable', $this->admit($first));
        for ($i = 0; $i < 3; $i++) $this->party('2026-11-21T10:00:00-06:00');
        $this->assertNotSame('unavailable', $this->admit($first), 'four at once including itself is allowed');
        $fifth = $this->party('2026-11-21T10:30:00-06:00', status: 'draft');
        $this->expectException(RecordWriteRejected::class);
        DB::transaction(fn () => AdmitEventCapacity::assertFits($fifth->fresh()));
    }

    public function test_two_drafts_for_the_last_costume_only_the_first_commitment_fits(): void
    {
        $a = $this->party('2026-11-21T14:00:00-06:00', elsa: true, status: 'draft');
        $b = $this->party('2026-11-21T14:30:00-06:00', elsa: true, status: 'draft');
        // Neither holds anything yet: both could fit on their own.
        $this->assertNotSame('unavailable', $this->admit($a));
        $this->assertNotSame('unavailable', $this->admit($b));
        // A takes the only Elsa (what an unpaid Stripe hold or a confirmation writes under the same day locks).
        DB::transaction(function () use ($a) {
            AdmitEventCapacity::assertFits($a->fresh());
            ResourceReservation::create(['event_id' => $a->id, 'resource_id' => Resource::where('code', 'ELSA-1')->value('id'), 'starts_at' => $a->starts_at, 'ends_at' => $a->ends_at]);
        });
        $this->assertSame('unavailable', $this->admit($b));
        $this->assertNotSame('unavailable', $this->admit($a), 'its own reservation never blocks it');
    }

    public function test_people_short_stays_the_owners_call_and_unknown_timing_is_never_instant(): void
    {
        // 2 performers: a third party at once has nobody, but the owner can call someone in.
        $this->party('2026-11-21T10:00:00-06:00'); $this->party('2026-11-21T10:00:00-06:00');
        $third = $this->party('2026-11-21T10:00:00-06:00', status: 'draft');
        $this->assertSame('owner_approval_required', $this->admit($third));
        DB::transaction(fn () => AdmitEventCapacity::assertFits($third->fresh()));
        $unknown = $this->party('2026-11-22T10:00:00-06:00', status: 'draft', setup: null);
        $this->assertSame('owner_approval_required', $this->admit($unknown));
    }

    public function test_day_locks_need_a_transaction_and_cover_a_party_near_midnight(): void
    {
        try { AdmitEventCapacity::lockDays(CarbonImmutable::parse('2026-11-21T23:30:00-06:00'), CarbonImmutable::parse('2026-11-22T00:30:00-06:00')); $this->fail('outside a transaction'); }
        catch (RecordWriteRejected) {}
        DB::transaction(fn () => AdmitEventCapacity::lockDays(CarbonImmutable::parse('2026-11-21T23:30:00-06:00'), CarbonImmutable::parse('2026-11-22T00:30:00-06:00')));
        $this->assertSame(['2026-11-21', '2026-11-22'], DB::table('capacity_day_locks')->orderBy('day')->pluck('day')->map(fn ($d) => substr($d, 0, 10))->all());
        // A party early on the 22nd reaches back into the 21st (margin), so it shares rows with the late one and they serialize.
        DB::transaction(fn () => AdmitEventCapacity::lockDays(CarbonImmutable::parse('2026-11-22T01:00:00-06:00'), CarbonImmutable::parse('2026-11-22T02:00:00-06:00')));
        $this->assertSame(2, DB::table('capacity_day_locks')->count());
    }
}
