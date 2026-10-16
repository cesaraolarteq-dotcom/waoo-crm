<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\ConfirmCapacity;
use App\Models\{BookingEvidence, Customer, Event, EventService, PriceVersion, Resource, ResourceReservation, ServiceVariant, User};
use App\Services\ConfirmBooking;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

// "Apartar recursos y confirmar" against the proposed ConfirmCapacity service. The bound stand-in hands the page's
// payload to Codex's real ConfirmBooking, so the payload shape and the business checks are exercised for real.
class ConfirmCapacityPageTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private Event $event;
    private Resource $performer;
    private Resource $speaker;
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $customer = Customer::create(['name' => 'Fictitious Client', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [
            ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer'], ['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'speaker']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $this->event = $this->makeEvent($customer, 'Fictitious Party', CarbonImmutable::parse('2026-12-19 21:00:00', 'UTC'), $variant, $price);
        $this->performer = Resource::create(['code' => 'STF-1', 'name' => 'Fictitious Performer', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer']]);
        $this->speaker = Resource::create(['code' => 'SPK-1', 'name' => 'Speaker 1', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []]);
    }

    private function makeEvent(Customer $c, string $title, CarbonImmutable $start, ServiceVariant $v, PriceVersion $p): Event
    {
        $e = Event::create(['customer_id' => $c->id, 'title' => $title, 'starts_at' => $start, 'ends_at' => $start->addHour(), 'timezone' => 'America/Chicago',
            'address' => 'Fictitious address 1', 'postal_code' => '00001', 'setup_minutes' => 30, 'breakdown_minutes' => 20, 'travel_fee_cents' => 2500]);
        EventService::create(['event_id' => $e->id, 'service_variant_id' => $v->id, 'price_version_id' => $p->id, 'requirements_snapshot' => $v->requirements, 'duration_minutes' => 60, 'operational_ready' => true]);

        return $e;
    }

    // Wraps Codex's real ConfirmCapacity service to record the exact payload the page sends.
    private function bindService(): void
    {
        $test = $this;
        $real = new \App\Services\Records\ConfirmCapacity;
        $this->app->instance(ConfirmCapacity::SERVICE, new class($test, $real) {
            public function __construct(private $test, private $real) {}
            public function execute(User $actor, int $eventId, array $input)
            {
                $this->test->recordCall($actor, $eventId, $input);

                return $this->real->execute($actor, $eventId, $input);
            }
        });
    }

    public function recordCall(User $actor, int $eventId, array $input): void
    {
        $this->calls[] = [$actor, $eventId, $input];
    }

    private function form(array $overrides = []): array
    {
        return array_replace_recursive([
            'units' => ['staff' => [$this->performer->id], 'equipment' => [$this->speaker->id]],
            'payment' => ['method' => 'external_zelle', 'reference' => 'ZELLE-FIXTURE-1', 'amount' => '62.50', 'date' => now('America/Chicago')->subDay()->format('Y-m-d'), 'time' => '09:00'],
            'staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Salen de la bodega ficticia y regresan ahí.',
        ], $overrides);
    }

    public function test_page_can_be_switched_off_and_is_on_with_the_service(): void
    {
        config(['waoo.record_forms' => false]);
        $this->get("/admin/eventos/{$this->event->id}/confirmar")->assertForbidden();
        config(['waoo.record_forms' => true]);
        $this->get("/admin/eventos/{$this->event->id}/editar")->assertSee('Apartar recursos y confirmar');
    }

    public function test_confirms_with_the_exact_payload_and_reserves_the_units(): void
    {
        $this->bindService();
        Livewire::test(ConfirmCapacity::class, ['record' => $this->event->id])
            ->assertSee('Fictitious Package: 1 personaje · 1 bocina')->assertSee('Total $125 · 50 % = $62.50')
            ->fillForm($this->form(['payment' => ['amount' => '63']]))->call('confirm')
            ->assertHasNoFormErrors()->assertNotified('Recursos apartados')->assertRedirect();

        [$actor, $eventId, $input] = $this->calls[0];
        $this->assertTrue($actor->is($this->owner));
        $this->assertSame($this->event->id, $eventId);
        $this->assertSame(1, $input['expected_version']);
        $this->assertSame([$this->performer->id, $this->speaker->id], $input['resource_ids']);
        $this->assertSame(6300, $input['payment']['amount_cents']);
        $this->assertMatchesRegularExpression('/T09:00:00-0[56]:00$/', $input['payment']['received_at']);
        $this->assertSame('capacity_reserved', $this->event->fresh()->status);
        $this->assertSame(2, ResourceReservation::where('event_id', $this->event->id)->count());
        $this->assertSame(6300, BookingEvidence::sole()->amount_cents);
        $this->assertSame($this->owner->id, (int) BookingEvidence::sole()->verified_by);
    }

    public function test_less_than_half_needs_a_reason(): void
    {
        $this->bindService();
        Livewire::test(ConfirmCapacity::class, ['record' => $this->event->id])
            ->fillForm($this->form(['payment' => ['amount' => '10']]))->call('confirm')
            ->assertHasFormErrors(['deposit_exception_reason']);
        $this->assertSame('draft', $this->event->fresh()->status);
    }

    public function test_business_refusal_is_explained_and_nothing_is_reserved(): void
    {
        $this->bindService();
        Livewire::test(ConfirmCapacity::class, ['record' => $this->event->id])
            ->fillForm([...$this->form(['payment' => ['amount' => '63']]), 'units' => ['staff' => [], 'equipment' => [$this->speaker->id]]])->call('confirm')
            ->assertNotified('No se apartó nada');
        $this->assertSame([$this->speaker->id], $this->calls[0][2]['resource_ids']);
        $this->assertSame('draft', $this->event->fresh()->status);
        $this->assertSame(0, ResourceReservation::count());
    }

    public function test_neighbor_event_adds_a_transfer_to_review(): void
    {
        $this->bindService();
        $variant = ServiceVariant::first();
        $earlier = $this->makeEvent(Customer::first(), 'Earlier party', $this->event->starts_at->subHours(4), $variant, PriceVersion::first());
        $earlier->forceFill(['status' => 'capacity_reserved'])->save();
        ResourceReservation::create(['event_id' => $earlier->id, 'resource_id' => $this->performer->id, 'starts_at' => $earlier->starts_at, 'ends_at' => $earlier->ends_at]);

        $page = Livewire::test(ConfirmCapacity::class, ['record' => $this->event->id])->fillForm($this->form(['payment' => ['amount' => '63']]));
        $transfers = array_values($page->get('data.transfers'));
        $this->assertCount(1, $transfers);
        $this->assertSame($earlier->id, $transfers[0]['from_event_id']);
        $this->assertSame($this->event->id, $transfers[0]['to_event_id']);
        $this->assertStringContainsString('Earlier party', $transfers[0]['label']);

        $filled = array_map(fn ($t) => [...$t, 'travel_minutes' => 40, 'contingency_minutes' => 15, 'note' => 'Google Maps ese sábado, 40 min.'], $page->get('data.transfers'));
        $page->set('data.transfers', $filled)->call('confirm')->assertHasNoFormErrors()->assertNotified('Recursos apartados');
        $this->assertSame(1, count($this->calls[0][2]['review']['transfers']));
        $this->assertSame('capacity_reserved', $this->event->fresh()->status);
    }
}
