<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EventContract;
use App\Filament\Resources\Variants\Pages\{CreateServiceVariant, EditServiceVariant};
use App\Models\{ClientDetailsReview, Contract, Event, PortalGrant, PriceVersion, ServiceVariant, User};
use App\Services\Booking\QuickBook;
use App\Services\Documents\{ActivateTemplateVersion, CreateTemplateVersion};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

// Claude's screens over Codex's real BOOK-QUICK + DOC services: quick booking → client details in the portal →
// owner approval → contract prefilled from approved details → issue; revoke link; catalog long description. Fixtures only.
class QuickDetailsFlowTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private PriceVersion $price;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'app.url' => 'http://localhost']);
        Queue::fake();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $this->price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $t = app(CreateTemplateVersion::class)->execute($this->owner, ['terms_html' => '<p>Fixture terms for {{ client.first_name }} only.</p>', 'change_note' => 'Fixture quick details flow'], (string) Str::uuid());
        app(ActivateTemplateVersion::class)->execute($this->owner, $t->id, ['expected_source_sha256' => $t->source_sha256, 'approval_note' => 'Fixture approval only']);
    }

    private function quickBook(): object
    {
        return app(QuickBook::class)->execute($this->owner, [
            'new_customer' => ['name' => 'Ana Ficticia', 'phone' => '555-0100', 'email' => null, 'locale' => 'es'],
            'title' => 'Cumpleaños de prueba', 'event_type' => 'birthday', 'starts_at' => '2026-12-19T15:00:00-06:00', 'ends_at' => '2026-12-19T16:00:00-06:00',
            'timezone' => 'America/Chicago', 'postal_code' => '00001', 'travel_fee_cents' => 2500, 'services' => [['price_version_id' => $this->price->id]],
            'deposit' => ['method' => 'zelle', 'amount_cents' => 6250, 'reference' => 'ZELLE-QD-1', 'received_at' => now()->subHour()->toIso8601String(), 'verified_received' => true],
        ], (string) Str::uuid());
    }

    public function test_catalog_long_description_reaches_the_service(): void
    {
        Livewire::test(CreateServiceVariant::class)->fillForm(['name' => 'Described Package', 'long_description' => '  The character arrives with a musical entrance.  ',
            'duration_minutes' => 30, 'status' => 'draft', 'requirements' => []])->call('create')->assertHasNoFormErrors();
        $this->assertSame('The character arrives with a musical entrance.', ServiceVariant::where('name', 'Described Package')->value('long_description'));
        $v = ServiceVariant::where('name', 'Described Package')->first();
        Livewire::test(EditServiceVariant::class, ['record' => $v->id])->fillForm(['long_description' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertNull($v->fresh()->long_description);
    }

    public function test_quick_booking_details_approval_and_contract(): void
    {
        $qb = $this->quickBook();
        $token = substr($qb->portal_url, strpos($qb->portal_url, 'token=') + 6);
        $event = Event::sole();

        // Owner sees the booking waiting for the client's details.
        $this->get("/admin/eventos/{$event->id}/contrato")->assertOk()->assertSee('Esperando sus datos');
        $this->get('/admin')->assertSee('Esperando datos de la clienta');

        // Client, in her own session (an owner session can never act for her).
        auth()->logout();
        $this->postJson('/portal/api/session', ['token' => $token])->assertOk();
        $this->get('/portal')->assertOk()->assertSee('Tus datos')->assertSee('id="details-form"', false)
            ->assertSee('data-event-version="'.$event->version.'"', false)->assertSee('data-revision="0"', false)->assertDontSee('name="sms_consent"', false);
        $this->postJson('/portal/api/details', ['expected_event_version' => $event->version, 'expected_revision' => 0, 'first_name' => 'Ana', 'last_name' => 'Ficticia',
            'phone' => '555-0100', 'email' => 'ana@example.test', 'billing_address' => 'Fixture billing 1', 'event_address' => 'Fixture party 2', 'event_postal_code' => '00001',
            'locale' => 'es', 'start_time_confirmed' => false, 'requested_start_at' => '2026-12-19T16:00:00-06:00'], ['Idempotency-Key' => (string) Str::uuid()])->assertSuccessful();
        $this->get('/portal')->assertSee('Recibimos tus datos');
        $this->actingAs($this->owner);

        // Owner reviews: requested time shown, approval needs explicit travel confirmation, keep-original and setup/breakdown.
        $this->get("/admin/eventos/{$event->id}/contrato")->assertSee('Por revisar')->assertSee('Pidió otra hora')->assertSee('Fixture party 2');
        $this->get('/admin')->assertSee('Datos de clienta por revisar');
        Livewire::test(EventContract::class, ['record' => $event->id])->assertActionVisible('approve')->assertActionHidden('create')
            ->callAction('approve', ['travel_fee_confirmed' => true, 'requested_start_resolution' => 'keep_original', 'setup_minutes' => 15, 'breakdown_minutes' => 15,
                'review_note' => 'Confirmé la dirección por WhatsApp; se queda la hora.'])->assertNotified('Datos aprobados');
        $this->assertSame(1, ClientDetailsReview::count());
        $this->assertSame('15:00', $event->fresh()->starts_at->setTimezone('America/Chicago')->format('H:i'), 'The party time never moves on approval.');

        // Contract is prefilled from the approved details and issues.
        Livewire::test(EventContract::class, ['record' => $event->id])->assertActionVisible('create')
            ->mountAction('create')->assertActionDataSet(['first_name' => 'Ana', 'last_name' => 'Ficticia', 'address' => 'Fixture billing 1', 'email' => 'ana@example.test'])
            ->callMountedAction()->assertNotified('Borrador del contrato listo');
        Livewire::test(EventContract::class, ['record' => $event->id])->callAction('issue')->assertNotified('Contrato emitido');
        $this->assertSame('issued', Contract::sole()->state);
        auth()->logout();
        $this->get('/portal')->assertSee('Tu contrato');
    }

    public function test_owner_can_revoke_a_link(): void
    {
        $qb = $this->quickBook();
        $token = substr($qb->portal_url, strpos($qb->portal_url, 'token=') + 6);
        $event = Event::sole();
        $grant = PortalGrant::sole();
        Livewire::test(EventContract::class, ['record' => $event->id])->callAction('revoke', [], ['id' => $grant->id])->assertNotified('Enlace anulado');
        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->postJson('/portal/api/session', ['token' => $token])->assertForbidden();
    }
}
