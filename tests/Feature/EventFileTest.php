<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, PriceVersion, Resource, ResourceReservation, ServiceVariant, User};
use App\Services\Records\SaveEventDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventFileTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'César Ficticio', 'role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        $customer = Customer::create(['name' => 'Fictitious Client', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $this->event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id, 'title' => 'Fiesta de prueba', 'event_type' => 'birthday',
            'starts_at' => '2026-12-19T15:00:00-06:00', 'ends_at' => '2026-12-19T16:00:00-06:00', 'timezone' => 'America/Chicago', 'postal_code' => '00001',
            'travel_fee_cents' => null, 'services' => [['price_version_id' => $price->id]]]);
    }

    public function test_draft_file_shows_details_services_history_and_edit(): void
    {
        $this->get('/admin/eventos')->assertSee("/admin/eventos/{$this->event->id}", false);
        $this->get("/admin/eventos/{$this->event->id}")->assertOk()
            ->assertSee('Fiesta de prueba')->assertSee('Fictitious Client')->assertSee('Cumpleaños')
            ->assertSee('Fictitious Package')->assertSee('$100')->assertSee('Pendiente')
            ->assertSee('Nada apartado todavía')->assertSee('Creó el borrador')->assertSee('César Ficticio')
            ->assertSee('Editar borrador');
    }

    public function test_booked_event_is_viewable_but_not_editable(): void
    {
        $unit = Resource::create(['code' => 'STF-1', 'name' => 'Fictitious Performer', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer']]);
        ResourceReservation::create(['event_id' => $this->event->id, 'resource_id' => $unit->id, 'starts_at' => $this->event->starts_at, 'ends_at' => $this->event->ends_at]);
        $this->event->forceFill(['status' => 'capacity_reserved'])->save();
        $this->get("/admin/eventos/{$this->event->id}")->assertOk()->assertSee('Fictitious Performer')->assertSee('Recursos apartados')->assertDontSee('Editar borrador');
        $this->get("/admin/eventos/{$this->event->id}/editar")->assertForbidden();
    }

    public function test_staff_cannot_open_an_event_file(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff', 'active' => true, 'two_factor_secret' => encrypt('X'), 'two_factor_confirmed_at' => now()]))
            ->get("/admin/eventos/{$this->event->id}")->assertForbidden();
    }
}
