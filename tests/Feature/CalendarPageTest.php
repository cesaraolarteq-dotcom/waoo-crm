<?php
namespace Tests\Feature;

use App\Filament\Pages\Calendar;
use App\Models\{Customer, Event, EventService, PriceVersion, Resource, ResourceReservation, ServiceVariant, User};
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarPageTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function party(string $title, string $chicagoStart, int $minutes, string $variantName, array $unitIds = []): Event
    {
        $customer = Customer::firstOrCreate(['name' => 'Fictitious Client'], ['locale' => 'es']);
        $variant = ServiceVariant::firstOrCreate(['name' => $variantName], ['status' => 'active', 'duration_minutes' => $minutes, 'requirements' => []]);
        $price = PriceVersion::firstOrCreate(['service_variant_id' => $variant->id], ['amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = CarbonImmutable::parse($chicagoStart, 'America/Chicago');
        $e = Event::create(['customer_id' => $customer->id, 'title' => $title, 'starts_at' => $start->utc(), 'ends_at' => $start->addMinutes($minutes)->utc(),
            'timezone' => 'America/Chicago', 'postal_code' => '00001', 'setup_minutes' => 30, 'breakdown_minutes' => 20, 'travel_fee_cents' => null]);
        EventService::create(['event_id' => $e->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id, 'requirements_snapshot' => [], 'duration_minutes' => $minutes, 'operational_ready' => true]);
        foreach ($unitIds as $id) {
            ResourceReservation::create(['event_id' => $e->id, 'resource_id' => $id, 'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at]);
        }
        if ($unitIds) {
            $e->forceFill(['status' => 'capacity_reserved'])->save();
        }

        return $e;
    }

    public function test_week_shows_parties_in_houston_time_with_package_color(): void
    {
        $this->party('Fiesta Mágica', '2026-12-19 15:00', 60, 'Mágico');
        $this->get('/admin/calendario?date=2026-12-19')->assertOk()->assertSee('Calendario')->assertSee('Lun')->assertDontSee('No hay fiestas este día')
            ->assertSee('Fiesta Mágica')->assertSee('3:00 p. m.', false)->assertSee('#8D53D9')->assertSee('Transporte Pendiente');
    }

    public function test_too_little_time_between_parties_sharing_a_unit_is_flagged(): void
    {
        $performer = Resource::create(['code' => 'STF-1', 'name' => 'Fictitious Performer', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer']]);
        $this->party('Primera', '2026-12-19 12:00', 60, 'Waoo', [$performer->id]);   // ends 1:00, breakdown → 1:20
        $this->party('Segunda', '2026-12-19 14:00', 60, 'Waoo', [$performer->id]);   // setup from 1:30 → 10 min free
        Livewire::test(Calendar::class)->set('date', '2026-12-19')->call('setMode', 'day')
            ->assertSee('Fictitious Performer: solo 10 min entre fiestas')->assertSee('Recursos apartados');
    }

    public function test_navigation_month_and_day(): void
    {
        $this->party('Fiesta', '2026-12-19 15:00', 60, 'Estrella');
        Livewire::test(Calendar::class)->set('date', '2026-12-19')->call('setMode', 'month')->assertSee('Diciembre 2026')->assertSee('Fiesta')
            ->call('openDay', '2026-12-19')->assertSet('mode', 'day')->assertSee('Sábado 19 de diciembre 2026')
            ->call('move', 1)->assertSet('date', '2026-12-20')->assertSee('No hay fiestas este día');
    }

    public function test_calendar_is_owner_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff', 'active' => true, 'two_factor_secret' => encrypt('X'), 'two_factor_confirmed_at' => now()]))
            ->get('/admin/calendario')->assertForbidden();
    }
}
