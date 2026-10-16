<?php
namespace Tests\Feature;

use App\Filament\Pages\ContractTemplates;
use App\Filament\Resources\Events\Pages\EventContract;
use App\Models\{Contract, ContractTemplateVersion, Customer, Event, EventService, PortalGrant, PriceVersion, ServiceVariant, User};
use App\Services\Finance\CreateFinancialRevision;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

// Owner contract screens against Codex's real sandbox Documents/Portal services. Fixture terms only, never the legal contract.
class ContractScreensTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'app.url' => 'http://localhost']);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $customer = Customer::create(['name' => 'Ana Ficticia', 'email' => 'ana@example.test', 'phone' => '555-0100', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = CarbonImmutable::parse('2026-12-19 15:00', 'America/Chicago');
        $this->event = Event::create(['customer_id' => $customer->id, 'title' => 'Fiesta', 'starts_at' => $start->utc(), 'ends_at' => $start->addHour()->utc(), 'timezone' => 'America/Chicago',
            'address' => 'Fixture address 1', 'postal_code' => '00001', 'setup_minutes' => 30, 'breakdown_minutes' => 20, 'travel_fee_cents' => 2500]);
        EventService::create(['event_id' => $this->event->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id, 'requirements_snapshot' => $variant->requirements, 'duration_minutes' => 60, 'operational_ready' => true]);
    }

    public function test_screens_are_off_outside_the_documents_sandbox(): void
    {
        config(['waoo.sandbox_documents' => false]);
        $this->get('/admin/contrato')->assertForbidden();
        $this->get("/admin/eventos/{$this->event->id}/contrato")->assertForbidden();
    }

    public function test_owner_flow_template_contract_issue_and_one_time_link(): void
    {
        Livewire::test(ContractTemplates::class)->assertSee('Todavía no hay plantilla')
            ->callAction('newVersion', ['terms_html' => '<h3>Fixture terms</h3><p>Test agreement only.</p>', 'change_note' => 'Fixture-only screen test'])
            ->assertNotified('Versión guardada como borrador');
        $t = ContractTemplateVersion::sole();
        Livewire::test(ContractTemplates::class)->callAction('activate', ['approval_note' => 'Fixture approval for the test'], ['id' => $t->id, 'version' => $t->version, 'sha' => $t->source_sha256])
            ->assertNotified('Versión activada');
        $this->assertSame('active', $t->fresh()->state);

        // Blockers are explained until amounts are fixed.
        Livewire::test(EventContract::class, ['record' => $this->event->id])->assertSee('Fija los montos acordados')->assertActionHidden('create');
        app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => $this->event->fresh()->version], (string) Str::uuid());

        Livewire::test(EventContract::class, ['record' => $this->event->id])->assertActionVisible('create')
            ->callAction('create', ['first_name' => 'Ana', 'last_name' => 'Ficticia', 'address' => 'Fixture billing 2', 'email' => 'ana@example.test', 'phone' => '555-0100'])
            ->assertNotified('Borrador del contrato listo');
        $contract = Contract::sole();
        $this->assertSame('draft', $contract->state);

        Livewire::test(EventContract::class, ['record' => $this->event->id])->callAction('issue')->assertNotified('Contrato emitido');
        $contract->refresh();
        $this->assertSame('issued', $contract->state);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract->presented_pdf_sha256);

        $page = Livewire::test(EventContract::class, ['record' => $this->event->id])->callAction('link', ['days' => 7])
            ->assertSee('/portal#token=', false)->assertSee('solo se muestra esta vez')->assertSee('¡Hola Ana!');
        $this->assertSame(1, PortalGrant::count());
        $page->call('hideLink')->assertDontSee('/portal#token=', false);
        $this->get("/admin/eventos/{$this->event->id}/contrato")->assertOk()->assertDontSee('#token=', false)->assertSee('Todavía no lo abre');
    }
}
