<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, EventService, PriceVersion, Resource, ServiceVariant, User};
use App\Services\Finance\{CreateFinancialRevision, RecordZellePayment};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardAttentionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'César Ficticio', 'role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
    }

    private function party(string $title, int $daysAhead, ?int $travel = 2500, bool $ready = true): Event
    {
        $customer = Customer::firstOrCreate(['name' => 'Fictitious Client'], ['locale' => 'es']);
        $variant = ServiceVariant::firstOrCreate(['name' => 'Fictitious Package'], ['status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::firstOrCreate(['service_variant_id' => $variant->id], ['amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = CarbonImmutable::now('America/Chicago')->addDays($daysAhead)->setTime(15, 0);
        $e = Event::create(['customer_id' => $customer->id, 'title' => $title, 'starts_at' => $start->utc(), 'ends_at' => $start->addHour()->utc(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => $travel]);
        EventService::create(['event_id' => $e->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id, 'requirements_snapshot' => [], 'duration_minutes' => 60, 'operational_ready' => $ready]);

        return $e;
    }

    public function test_nothing_pending_says_so(): void
    {
        $this->get('/admin')->assertOk()->assertSeeText('Hola, César')->assertSee('Todo al día')->assertSee('No hay fiestas de aquí al domingo')->assertSee('Ver calendario');
    }

    public function test_real_signals_become_cards_with_links(): void
    {
        $this->party('Fiesta pronto', 3);
        $this->party('Fiesta sin transporte', 30, null);
        $this->party('Fiesta incompleta', 40, 2500, false);
        Resource::create(['code' => 'TRJ-1', 'name' => 'Traje en reparación', 'kind' => 'costume', 'status' => 'maintenance', 'character_id' => \App\Models\Character::create(['name' => 'Hero', 'active' => true])->id, 'skills' => []]);

        $this->get('/admin')->assertOk()
            ->assertSee('Fiestas en menos de 7 días sin staff apartado')->assertSee('Fiesta pronto')
            ->assertSee('Costo de transporte pendiente')->assertSee('Fiesta sin transporte')
            ->assertSee('Borradores incompletos')->assertSee('Fiesta incompleta')
            ->assertSee('Unidades en mantenimiento')->assertSee('Traje en reparación')
            ->assertDontSee('Todo al día');
    }

    public function test_balances_due_this_week_appear_only_in_the_finance_sandbox(): void
    {
        $e = $this->party('Fiesta con saldo', 5);
        $this->get('/admin')->assertDontSee('Saldos que vencen esta semana');
        config(['waoo.sandbox_finance' => true]);
        $rev = app(CreateFinancialRevision::class)->execute($this->owner, $e->id, ['expected_version' => $e->fresh()->version], (string) Str::uuid());
        app(RecordZellePayment::class)->execute($this->owner, $e->id, ['financial_revision_id' => $rev->id, 'amount_cents' => 6250, 'currency' => 'USD', 'reference' => 'ZELLE-DASH-1', 'received_at' => now()->subHour()->toIso8601String(), 'verified_received' => true], (string) Str::uuid());
        $this->get('/admin')->assertSee('Saldos que vencen esta semana')->assertSee('saldo $62.50');
    }
}
