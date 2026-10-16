<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EventContract;
use App\Filament\Resources\Variants\Pages\{CreateServiceVariant, EditServiceVariant};
use App\Models\{ClientDetailsReview, Contract, Event, PortalGrant, PriceVersion, ServiceVariant, User};
use App\Services\Portal\{PortalContext, SubmitClientDetails};
use Illuminate\Http\Request;
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

    /** Client session + PortalContext for direct service calls (as Codex's review probe). */
    private function clientContext(object $qb): PortalContext
    {
        auth()->logout(); $this->app['auth']->forgetGuards();
        $this->postJson('/portal/api/session', ['token' => substr($qb->portal_url, strpos($qb->portal_url, 'token=') + 6)])->assertOk();
        $request = Request::create('/portal/api/details', 'POST');
        $request->setLaravelSession($this->app['session.store']); $request->setUserResolver(fn () => null);

        return PortalContext::fromRequest($request);
    }

    private function details(Event $event, int $revision, string $venue, string $zip = '00001'): array
    {
        return ['expected_event_version' => $event->version, 'expected_revision' => $revision, 'first_name' => 'Fixture', 'last_name' => 'Client', 'phone' => '555-0100',
            'email' => 'fixture@example.test', 'billing_address' => 'Fixture billing', 'event_address' => $venue, 'event_postal_code' => $zip, 'start_time_confirmed' => true];
    }

    // Codex review R1: the approval is pinned to the submission shown in the modal; a newer one needs a refresh and a new review.
    public function test_approval_never_switches_to_a_submission_sent_after_the_modal_opened(): void
    {
        $qb = $this->quickBook(); $event = Event::sole(); $client = $this->clientContext($qb);
        app(SubmitClientDetails::class)->execute($client, $this->details($event, 0, 'Fixture venue ONE'), (string) Str::uuid());
        $this->actingAs($this->owner);
        $page = Livewire::test(EventContract::class, ['record' => $event->id])->assertSee('Fixture venue ONE')->mountAction('approve')
            ->assertSee('Fixture venue ONE')
            ->setActionData(['travel_fee_confirmed' => true, 'setup_minutes' => 15, 'breakdown_minutes' => 15, 'review_note' => 'Owner reviewed only fixture venue ONE.']);
        app(SubmitClientDetails::class)->execute($client, $this->details($event, 1, 'Fixture venue TWO far away', '99999'), (string) Str::uuid());
        $page->callMountedAction()->assertNotified('La clienta mandó datos nuevos');
        $this->assertSame(0, ClientDetailsReview::count());
        $this->assertNotSame('Fixture venue TWO far away', $event->fresh()->address);

        // After a refresh the owner sees and approves version 2 explicitly.
        Livewire::test(EventContract::class, ['record' => $event->id])->mountAction('approve')->assertSee('versión 2')->assertSee('Fixture venue TWO far away')
            ->setActionData(['travel_fee_confirmed' => true, 'setup_minutes' => 15, 'breakdown_minutes' => 15, 'review_note' => 'Revisé la dirección nueva y el ZIP.'])
            ->callMountedAction()->assertNotified('Datos aprobados');
        $this->assertSame(2, \App\Models\ClientDetailsSubmission::findOrFail(ClientDetailsReview::sole()->submission_id)->revision);
    }

    // Codex review C1: the checkbox shows exactly the configured wording of the version it sends.
    public function test_sms_checkbox_shows_the_exact_approved_wording(): void
    {
        $qb = $this->quickBook(); $this->clientContext($qb);
        $this->get('/portal')->assertDontSee('name="sms_consent"', false);
        config(['waoo.sms_consent_versions' => ['fixture-v2-es' => 'Texto aprobado de prueba para SMS.', 'fixture-v2-en' => 'Fixture approved SMS wording.']]);
        $this->get('/portal?lang=es')->assertSee('data-version="fixture-v2-es"', false)->assertSee('Texto aprobado de prueba para SMS.');
        $this->get('/portal?lang=en')->assertSee('data-version="fixture-v2-en"', false)->assertSee('Fixture approved SMS wording.');
    }

    // Codex review R2: the requested time is resolved in Houston (DST-aware), never with the original start's offset.
    public function test_requested_time_is_resolved_in_houston_across_dst(): void
    {
        $qb = $this->quickBook(); $this->clientContext($qb);
        $html = $this->get('/portal')->assertOk()->assertDontSee('data-event-offset', false)->getContent();
        preg_match('/function houston\(date, time\) \{.*?\n  \}/s', $html, $m);
        $this->assertNotEmpty($m, 'houston() helper not found in the portal page');
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestIncomplete('node not available to execute the portal helper');
        }
        $js = $m[0].";\nconsole.log(JSON.stringify([houston('2026-11-01','03:30'), houston('2026-11-01','01:30'), houston('2026-03-08','02:30'), houston('2026-03-08','03:30'), houston('2026-12-19','16:00')]));";
        $file = tempnam(sys_get_temp_dir(), 'houston').'.cjs'; file_put_contents($file, $js);
        $out = json_decode((string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file)), true); @unlink($file);
        $this->assertSame(['2026-11-01T03:30:00-06:00', 'ambiguous', null, '2026-03-08T03:30:00-05:00', '2026-12-19T16:00:00-06:00'], $out);
    }
}
