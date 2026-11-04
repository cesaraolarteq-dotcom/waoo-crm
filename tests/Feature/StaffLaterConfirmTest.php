<?php
namespace Tests\Feature;

use App\Models\{BookingEvidence, Customer, Event, EventService, PriceVersion, Resource, ServiceVariant, User};
use App\Services\Records\ConfirmCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Owner 2026-11-02: confirm a booking when the deposit arrives without naming people; the team is planned the week of the party. */
class StaffLaterConfirmTest extends TestCase
{
    use RefreshDatabase;

    private User $owner; private ServiceVariant $variant; private PriceVersion $price; private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->variant = ServiceVariant::create(['name' => 'Música y Juegos', 'status' => 'active', 'duration_minutes' => 60,
            'requirements' => [['kind' => 'staff', 'skill' => 'host', 'quantity' => 1], ['kind' => 'staff', 'skill' => 'driver', 'quantity' => 1]]]);
        $this->price = PriceVersion::create(['service_variant_id' => $this->variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        Resource::create(['code' => 'ANA', 'name' => 'Ana Rodríguez', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'driver']]);
        Resource::create(['code' => 'LUIS', 'name' => 'Luis Pérez', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'driver']]);
    }

    private function party(string $start): Event
    {
        $c = Customer::create(['name' => 'María González '.(++$this->n), 'locale' => 'es']);
        $s = now()->addDays(20)->setTimeFromTimeString($start);
        $e = Event::create(['customer_id' => $c->id, 'title' => 'Fiesta '.$this->n, 'starts_at' => $s, 'ends_at' => $s->copy()->addHour(), 'timezone' => 'America/Chicago',
            'address' => '123 Oak Street', 'setup_minutes' => 15, 'breakdown_minutes' => 15, 'travel_fee_cents' => 0]);
        EventService::create(['event_id' => $e->id, 'service_variant_id' => $this->variant->id, 'price_version_id' => $this->price->id,
            'requirements_snapshot' => $this->variant->requirements, 'duration_minutes' => 60, 'operational_ready' => true]);
        return $e;
    }

    private function confirm(Event $e): Event
    {
        return app(ConfirmCapacity::class)->execute($this->owner, $e->id, ['expected_version' => $e->fresh()->version, 'staff_later' => true, 'resource_ids' => [],
            'payment' => ['method' => 'external_zelle', 'reference' => 'ZELLE-'.$e->id, 'amount_cents' => 10000, 'received_at' => now()->subHour()->toIso8601String()],
            'review' => ['transfers' => []]]);
    }

    public function test_deposit_confirms_without_people_and_records_staff_later(): void
    {
        $saved = $this->confirm($this->party('14:00'));
        $this->assertSame('capacity_reserved', $saved->status);
        $this->assertCount(0, $saved->reservations);
        $this->assertSame('later', BookingEvidence::where('event_id', $saved->id)->value('staff_assignment_snapshot')['staff_mode']);
    }

    public function test_address_can_wait_for_the_client_link_only_in_staff_later_mode(): void
    {
        $e = $this->party('11:00'); $e->forceFill(['address' => null])->save();
        try {
            app(ConfirmCapacity::class)->execute($this->owner, $e->id, ['expected_version' => $e->fresh()->version, 'resource_ids' => [Resource::where('code', 'ANA')->value('id')],
                'payment' => ['method' => 'external_zelle', 'reference' => 'ZELLE-NOADDR', 'amount_cents' => 10000, 'received_at' => now()->subHour()->toIso8601String()],
                'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'transfers' => []]]);
            $this->fail('Named people without an address.');
        } catch (ValidationException $x) { $this->assertStringContainsString('Falta la dirección', collect($x->errors())->flatten()->first()); }
        $this->assertSame('capacity_reserved', $this->confirm($e)->status);
    }

    public function test_short_people_is_the_owners_call_but_missing_equipment_is_refused(): void
    {
        $this->confirm($this->party('14:00')); $this->confirm($this->party('14:00')); // both hosts are now counted at 2 pm
        $third = $this->confirm($this->party('14:00')); // owner decision 2026-10-28: people short = owner's call
        $this->assertSame('capacity_reserved', $third->status);
        // A hard unit limit (the only speaker) is refused.
        $speaker = ServiceVariant::create(['name' => 'Bocina', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'equipment', 'equipment_kind' => 'speaker', 'quantity' => 1]]]);
        $p = PriceVersion::create(['service_variant_id' => $speaker->id, 'amount_cents' => 5000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        Resource::create(['code' => 'BOC-1', 'name' => 'Bocina 1', 'kind' => 'equipment', 'equipment_kind' => 'speaker', 'status' => 'active', 'skills' => []]);
        $withSpeaker = function (string $t) use ($speaker, $p) { $e = $this->party($t); EventService::create(['event_id' => $e->id, 'service_variant_id' => $speaker->id, 'price_version_id' => $p->id,
            'requirements_snapshot' => $speaker->requirements, 'duration_minutes' => 60, 'operational_ready' => true]); return $e; };
        $this->confirm($withSpeaker('17:00'));
        try { $this->confirm($withSpeaker('17:00')); $this->fail('Second party with the only speaker was confirmed.'); }
        catch (\App\Exceptions\RecordWriteRejected $e) { $this->assertSame(409, $e->status); $this->assertStringContainsString('no alcanzan', $e->getMessage()); }
    }

    public function test_people_cannot_be_named_in_staff_later_mode_and_normal_mode_still_needs_resources(): void
    {
        $e = $this->party('10:00');
        try {
            app(ConfirmCapacity::class)->execute($this->owner, $e->id, ['expected_version' => 1, 'staff_later' => true, 'resource_ids' => [Resource::where('code', 'ANA')->value('id')],
                'payment' => ['method' => 'external_zelle', 'reference' => 'ZELLE-X', 'amount_cents' => 5000, 'received_at' => now()->subHour()->toIso8601String()], 'review' => ['transfers' => []]]);
            $this->fail('Named person accepted in staff-later mode.');
        } catch (ValidationException $x) { $this->assertStringContainsString('Planear el fin de semana', collect($x->errors())->flatten()->first()); }
        try {
            app(ConfirmCapacity::class)->execute($this->owner, $e->id, ['expected_version' => 1, 'resource_ids' => [],
                'payment' => ['method' => 'external_zelle', 'reference' => 'ZELLE-Y', 'amount_cents' => 5000, 'received_at' => now()->subHour()->toIso8601String()],
                'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Salen de la bodega en Katy.', 'transfers' => []]]);
            $this->fail('Normal mode accepted an empty team.');
        } catch (ValidationException $x) { $this->assertArrayHasKey('booking', $x->errors()); }
    }

    public function test_extra_time_makes_the_same_team_stay_longer(): void
    {
        // Owner 2026-11-04: «Hora extra» ($200) has no staff of its own; the party must cover package + extra.
        $hour = ServiceVariant::create(['name' => 'Hora extra', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [], 'extends_event' => true]);
        $p = PriceVersion::create(['service_variant_id' => $hour->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $this->assertSame(120, ServiceVariant::partyMinutes([[$this->variant, 60], [$hour, 60]]));
        $short = $this->party('14:00');
        EventService::create(['event_id' => $short->id, 'service_variant_id' => $hour->id, 'price_version_id' => $p->id, 'requirements_snapshot' => [], 'duration_minutes' => 60, 'operational_ready' => true]);
        try { $this->confirm($short); $this->fail('A one-hour party took a package of one hour plus an extra hour.'); }
        catch (ValidationException $x) { $this->assertStringContainsString('120 minutos', collect($x->errors())->flatten()->first()); }
        $short->forceFill(['ends_at' => $short->starts_at->addHours(2)])->save();
        $this->assertSame('capacity_reserved', $this->confirm($short)->status);
    }
}
