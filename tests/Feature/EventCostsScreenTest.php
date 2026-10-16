<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EventCosts;
use App\Models\{Customer, Event, EventCostSnapshot, FinancialRevision, PriceVersion, ServiceVariant, User};
use App\Services\Costs\RecordCostSnapshot;
use App\Services\Finance\CreateFinancialRevision;
use App\Services\Records\SaveEventDraft;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

// Claude's "Costos y margen" screen over Codex's COST-A services: owner-typed costs only, unknown/missing coverage
// shows the margin as pending (never $0), estimate and actual stay separate, and a stale editor gets a refusal, not an overwrite.
class EventCostsScreenTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private Event $event;
    private FinancialRevision $finance;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]);
        Queue::fake();
        $this->owner = User::factory()->create(['name' => 'Dueña Ficticia', 'role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $customer = Customer::create(['name' => 'Fixture Client', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Paquete ficticio', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = now()->addDays(20)->startOfHour();
        $this->event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id, 'title' => 'Fiesta de costos', 'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => 5500, 'services' => [['price_version_id' => $price->id]]]);
        $this->finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
    }

    private function line(string $label, string $category, ?string $amount, ?int $service = null): array
    {
        return ['label' => $label, 'category' => $category, 'amount' => $amount, 'event_service_id' => $service];
    }

    public function test_pending_until_every_cost_is_known_and_coverage_confirmed(): void
    {
        $this->get("/admin/eventos/{$this->event->id}")->assertSee('Costos y margen');
        $this->get("/admin/eventos/{$this->event->id}/costos")->assertOk()->assertSee('Ingreso acordado')->assertSee('$455')
            ->assertSee('Pendiente')->assertSee('Todavía no hay costos registrados.')->assertDontSee('$0 ');

        $service = $this->finance->lines_snapshot[0]['event_service_id'];
        Livewire::test(EventCosts::class, ['record' => $this->event->id])->callAction('estimate', [
            'lines' => [$this->line('Animadora', 'staff_labor', '120', $service), $this->line('Materiales de slime', 'materials', null)],
            'coverage_confirmed' => true, 'note' => 'Falta confirmar el costo de materiales.',
        ])->assertHasNoActionErrors()->assertNotified('Costos guardados');
        $this->assertNull(EventCostSnapshot::sole()->lines_snapshot[1]['amount_cents'], 'An empty amount is unknown, never $0.');
        $this->get("/admin/eventos/{$this->event->id}/costos")->assertSee('Pendiente')->assertSee('Faltan 1 costo por escribir.')
            ->assertSee('Costos que ya escribiste: $120 (no es el total)')->assertSee('Falta')->assertSee('Paquete ficticio');

        // Second estimate starts from the first (owner's own amounts), now complete: margin = 455 - 120 - 30 = 305.
        $page = Livewire::test(EventCosts::class, ['record' => $this->event->id])->mountAction('estimate');
        $this->assertEquals([$this->line('Animadora', 'staff_labor', '120', $service), $this->line('Materiales de slime', 'materials', null)],
            array_values($page->instance()->mountedActions[0]['data']['lines']));
        $page->setActionData(['lines' => [$this->line('Animadora', 'staff_labor', '120', $service), $this->line('Materiales de slime', 'materials', '30')],
                'coverage_confirmed' => true, 'note' => 'Todos los costos directos confirmados.'])
            ->callMountedAction()->assertNotified('Costos guardados');
        $this->get("/admin/eventos/{$this->event->id}/costos")->assertSee('Margen de esta fiesta')->assertSee('$305')->assertSee('67.0 %')
            ->assertSee('Historial de costos')->assertSee('Estimado · versión 2');
        $this->get("/admin/eventos/{$this->event->id}")->assertSee('Registró costos');
    }

    public function test_missing_coverage_keeps_margin_pending_and_actual_is_separate(): void
    {
        Livewire::test(EventCosts::class, ['record' => $this->event->id])->callAction('actual', [
            'lines' => [$this->line('Animadora', 'staff_labor', '150')], 'coverage_confirmed' => false, 'note' => 'Todavía falta el transporte.',
        ])->assertNotified('Costos guardados');
        $this->get("/admin/eventos/{$this->event->id}/costos")->assertSee('No has confirmado que están todos los costos.')->assertDontSee('$305');
        $this->assertSame(['actual'], EventCostSnapshot::pluck('kind')->all());
    }

    public function test_zero_costs_need_explicit_coverage_and_a_note(): void
    {
        Livewire::test(EventCosts::class, ['record' => $this->event->id])->callAction('estimate', ['lines' => [], 'coverage_confirmed' => true, 'note' => 'corto'])
            ->assertHasActionErrors(['note']);
        Livewire::test(EventCosts::class, ['record' => $this->event->id])->callAction('estimate', ['lines' => [], 'coverage_confirmed' => true, 'note' => 'Fiesta de cortesía, sin costos directos.'])
            ->assertNotified('Costos guardados');
        $this->get("/admin/eventos/{$this->event->id}/costos")->assertSee('Sin costos (confirmaste que no hubo ninguno).')->assertSee('$455');
    }

    public function test_a_stale_editor_is_refused_instead_of_overwriting(): void
    {
        $page = Livewire::test(EventCosts::class, ['record' => $this->event->id]);
        // Another session saves an estimate first.
        app(RecordCostSnapshot::class)->execute($this->owner, $this->event->id, ['kind' => 'estimate', 'expected_event_version' => 1, 'financial_revision_id' => $this->finance->id,
            'expected_revision' => 0, 'coverage_confirmed' => true, 'note' => 'Guardado en otra pestaña.', 'lines' => []], (string) Str::uuid());
        $page->callAction('estimate', ['lines' => [$this->line('Animadora', 'staff_labor', '99')], 'coverage_confirmed' => true, 'note' => 'Intento con datos viejos.'])
            ->assertNotified('No se guardó');
        $this->assertSame(1, EventCostSnapshot::count());
        $this->assertSame('Guardado en otra pestaña.', EventCostSnapshot::sole()->note);
    }

    public function test_without_agreed_amounts_there_is_no_revenue_or_form(): void
    {
        $other = $this->event->replicate(); $other->save();
        $this->get("/admin/eventos/{$other->id}/costos")->assertOk()->assertSee('Primero fija los montos acordados');
        Livewire::test(EventCosts::class, ['record' => $other->id])->assertActionHidden('estimate')->assertActionHidden('actual');
    }

    public function test_hidden_when_finance_sandbox_is_off(): void
    {
        config(['waoo.sandbox_finance' => false]);
        $this->get("/admin/eventos/{$this->event->id}/costos")->assertForbidden();
    }
}
