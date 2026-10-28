<?php
namespace Tests\Feature;

use App\Filament\Pages\Leads;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\{CreateEvent, ViewEvent};
use App\Models\{Customer, Event, Lead, PriceVersion, Quote, ServiceVariant, User};
use App\Services\Leads\ReceiveWebsiteLead;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

// "Crear cotización" on a website lead (César 2026-10-28): the event draft opens prefilled, a new person becomes a
// client only when the draft is saved, the quote modal opens right away and the lead turns "quoted" with the quote.
class LeadQuoteTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'fixture-shared-secret-fixture-shared-secret-123';

    private PriceVersion $price;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'waoo.sandbox_quotes' => true, 'waoo.sandbox_lead_intake' => true,
            'waoo.lead_shared_secret' => self::SECRET, 'app.url' => 'http://localhost']);
        Queue::fake();
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant = ServiceVariant::create(['name' => 'Mágico (Magic)', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $this->price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 37500, 'currency' => 'USD', 'created_by' => $owner->id]);
    }

    private function lead(array $over = []): Lead
    {
        $body = json_encode([...['name' => 'María González', 'phone' => '(346) 555-0100', 'email' => 'maria@example.test', 'event_date' => '2026-12-19', 'start_time' => '15:00',
            'zip' => '77494', 'event_type' => 'Birthday', 'package' => 'Magic Package', 'message' => 'Quiere a Elsa.', 'travel_estimate_text' => '$40',
            'locale' => 'es', 'demand_flag' => 'filling', 'submitted_at' => now()->toIso8601String(), 'page' => '/contact', 'consent' => null], ...$over]);
        $ts = (string) time(); $key = (string) Str::uuid();

        return app(ReceiveWebsiteLead::class)->execute($body, $ts, $key, 'sha256='.hash_hmac('sha256', $ts."\n".$key."\n".$body, self::SECRET));
    }

    public function test_new_lead_becomes_client_and_quote_in_one_flow(): void
    {
        $lead = $this->lead();
        Livewire::test(Leads::class)->assertSee('Crear cotización');

        $page = Livewire::test(CreateEvent::class)->call('fillFromLead', $lead->id)
            ->assertSet('leadId', $lead->id)->assertSet('leadVersion', 1)->assertSet('leadClient.name', 'María González')
            ->assertSchemaStateSet(['date' => '2026-12-19', 'start_time' => '15:00', 'end_time' => '16:00', 'postal_code' => '77494',
                'event_type' => 'birthday', 'price_version_ids' => [(string) $this->price->id]])
            ->assertSee('Desde la web: María González')->assertSee('Quiere a Elsa.');
        $this->assertSame(0, Customer::count(), 'Opening the page writes nothing.');
        $this->assertSame('new', $lead->fresh()->status);

        $page->fillForm(['travel_fee' => '40'])->call('create')->assertHasNoFormErrors();
        $client = Customer::sole();
        $event = Event::sole();
        $this->assertSame(['María González', 'maria@example.test'], [$client->name, $client->email]);
        $this->assertSame($client->id, $event->customer_id);
        $page->assertRedirect(EventResource::getUrl('view', ['record' => $event, 'action' => 'quoteCreate', 'lead' => $lead->id, 'lead_version' => 1]));

        Livewire::withQueryParams(['lead' => $lead->id, 'lead_version' => 1])->test(ViewEvent::class, ['record' => $event->id])
            ->callAction('quoteCreate', ['locale' => 'es', 'discounts' => []])->assertHasNoActionErrors();
        $this->assertSame(1, Quote::count());
        $this->assertSame('quoted', $lead->fresh()->status);
    }

    public function test_known_client_is_preselected_and_not_duplicated(): void
    {
        $client = Customer::create(['name' => 'María González', 'email' => 'maria@example.test', 'locale' => 'es']);
        $lead = $this->lead();
        Livewire::test(CreateEvent::class)->call('fillFromLead', $lead->id)
            ->assertSet('leadClient', null)->assertSchemaStateSet(['customer_id' => $client->id])
            ->fillForm(['travel_fee' => '40'])->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, Customer::count());
        $this->assertSame($client->id, Event::sole()->customer_id);
    }

    public function test_new_quote_button_on_quotes_page_opens_the_quote_after_save(): void
    {
        $this->get(\App\Filament\Pages\Quotes::getUrl())->assertOk()->assertSee('Nueva cotización')->assertSee('Desde un prospecto');
        $client = Customer::create(['name' => 'Sofía Méndez', 'locale' => 'es']);
        $page = Livewire::withQueryParams(['cotizar' => 1])->test(CreateEvent::class)->assertSet('quoteAfterSave', true)
            ->fillForm(['customer_id' => $client->id, 'title' => 'Fiesta Sofía', 'date' => '2026-12-19', 'start_time' => '15:00', 'end_time' => '16:00',
                'price_version_ids' => [(string) $this->price->id], 'travel_fee' => '40'])
            ->call('create')->assertHasNoFormErrors();
        $page->assertRedirect(EventResource::getUrl('view', ['record' => Event::sole(), 'action' => 'quoteCreate']));
    }

    public function test_a_client_without_name_does_not_break_the_new_event_page(): void
    {
        // Quick booking may leave a provisional client with no name (crm-test 500, 2026-10-28).
        Customer::create(['name' => null, 'phone' => '(346) 555-0199', 'locale' => 'es']);
        $lead = $this->lead();
        $this->get(EventResource::getUrl('create', ['lead' => $lead->id]))->assertOk();
        $this->assertContains('Cliente sin nombre ((346) 555-0199)', EventResource::customerOptions());
        $this->get(EventResource::getUrl('create'))->assertOk();
    }

    public function test_plain_new_event_still_requires_a_client(): void
    {
        Livewire::test(CreateEvent::class)
            ->fillForm(['title' => 'Fiesta sin cliente', 'date' => '2026-12-19', 'start_time' => '15:00', 'end_time' => '16:00', 'price_version_ids' => [(string) $this->price->id]])
            ->call('create')->assertHasFormErrors(['customer_id' => 'required']);
        $this->assertSame(0, Event::count());
    }
}
