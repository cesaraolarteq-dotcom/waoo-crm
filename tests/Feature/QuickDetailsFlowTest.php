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

    private function quickBook(?string $start = null): object
    {
        return app(QuickBook::class)->execute($this->owner, [
            'new_customer' => ['name' => 'Ana Ficticia', 'phone' => '555-0100', 'email' => null, 'locale' => 'es'],
            'title' => 'Cumpleaños de prueba', 'event_type' => 'birthday', 'starts_at' => $start ?? '2026-12-19T15:00:00-06:00', 'ends_at' => $start ? \Carbon\CarbonImmutable::parse($start)->addHour()->toIso8601String() : '2026-12-19T16:00:00-06:00',
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
        $this->get('/admin')->assertSee('Esperando datos del cliente');

        // Client, in her own session (an owner session can never act for her).
        auth()->logout();
        $this->postJson('/portal/api/session', ['token' => $token])->assertOk();
        $page = $this->get('/portal')->assertOk()->assertSee('Tus datos')->assertSee('id="details-form"', false)
            ->assertSee('data-event-version="'.$event->version.'"', false)->assertSee('data-revision="0"', false)
            ->assertSee('name="sms_consent"', false)->assertSee('data-version="client-texts-2026-10-20-sms-es"', false)
            ->assertSee(config('waoo.sms_consent_versions.client-texts-2026-10-20-sms-es'));
        // Approved wording is available, but consent is never presumed or selected for the client.
        $dom = new \DOMDocument(); @$dom->loadHTML($page->getContent());
        $checkbox = (new \DOMXPath($dom))->query('//input[@name="sms_consent"]')->item(0);
        $this->assertNotNull($checkbox); $this->assertFalse($checkbox->hasAttribute('checked'));
        $this->postJson('/portal/api/details', ['expected_event_version' => $event->version, 'expected_revision' => 0, 'first_name' => 'Ana', 'last_name' => 'Ficticia',
            'phone' => '555-0100', 'email' => 'ana@example.test', 'billing_address' => 'Fixture billing 1', 'event_address' => 'Fixture party 2', 'event_postal_code' => '00001',
            'locale' => 'es', 'start_time_confirmed' => false, 'requested_start_at' => '2026-12-19T16:00:00-06:00'], ['Idempotency-Key' => (string) Str::uuid()])->assertSuccessful();
        $this->get('/portal')->assertSee('Recibimos tus datos');
        $this->actingAs($this->owner);

        // Owner reviews: requested time shown, approval needs explicit travel confirmation, keep-original and setup/breakdown.
        $this->get("/admin/eventos/{$event->id}/contrato")->assertSee('Por revisar')->assertSee('Pidió otra hora')->assertSee('Fixture party 2');
        $this->get('/admin')->assertSee('Datos de cliente por revisar');
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
    private function clientFixture(?string $start = null): array
    {
        $qb = $this->quickBook($start);
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->postJson('/portal/api/session', ['token' => substr($qb->portal_url, strpos($qb->portal_url, 'token=') + 6)])->assertOk();
        $request = \Illuminate\Http\Request::create('/portal/api/details', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        $request->setUserResolver(fn () => null);
        $client = \App\Services\Portal\PortalContext::fromRequest($request);
        $event = Event::sole();
        return [$client, $event, ['expected_event_version' => $event->version, 'expected_revision' => 0,
            'first_name' => 'Fixture', 'last_name' => 'Client', 'phone' => '555-0100', 'email' => 'fixture@example.test',
            'billing_address' => 'Fixture billing', 'event_address' => 'Fixture venue ONE', 'event_postal_code' => '00001', 'start_time_confirmed' => true]];
    }

    public function test_approval_pins_the_displayed_submission_across_a_client_resubmission(): void
    {
        [$client, $event, $input] = $this->clientFixture();
        $service = app(\App\Services\Portal\SubmitClientDetails::class);
        $first = $service->execute($client, $input, (string) Str::uuid());
        $this->actingAs($this->owner);
        $page = Livewire::test(EventContract::class, ['record' => $event->id])->assertSee('Fixture venue ONE')->mountAction('approve')
            ->setActionData(['travel_fee_confirmed' => true, 'setup_minutes' => 15, 'breakdown_minutes' => 15, 'review_note' => 'Owner reviewed only fixture venue ONE.']);
        $second = $service->execute($client, [...$input, 'expected_revision' => 1, 'event_address' => 'Fixture venue TWO far away', 'event_postal_code' => '99999'], (string) Str::uuid());
        $before = $event->fresh()->getAttributes();
        $financeCount = \App\Models\FinancialRevision::count();
        $auditCount = \Illuminate\Support\Facades\DB::table('audit_entries')->count();
        $page->callMountedAction();
        $this->assertSame(0, ClientDetailsReview::count());
        $this->assertSame($before, $event->fresh()->getAttributes());
        $this->assertSame($financeCount, \App\Models\FinancialRevision::count());
        $this->assertSame($auditCount, \Illuminate\Support\Facades\DB::table('audit_entries')->count());
        $page->assertSet('displayedSubmission.id', $first->id)->assertSee('Fixture venue ONE')->assertDontSee('Fixture venue TWO far away');
        // Reload shows the new immutable submission; it can be explicitly reviewed and approved normally.
        Livewire::test(EventContract::class, ['record' => $event->id])->assertSee('Fixture venue TWO far away')
            ->callAction('approve', ['travel_fee_confirmed' => true, 'setup_minutes' => 15, 'breakdown_minutes' => 15, 'review_note' => 'Owner now reviewed fixture venue TWO.'])
            ->assertNotified('Datos aprobados');
        $this->assertSame($second->id, ClientDetailsReview::sole()->submission_id);
        $this->assertSame('Fixture venue TWO far away', $event->fresh()->address);
    }

    public function test_approval_snapshot_is_not_replaced_when_the_modal_opens(): void
    {
        [$client, $event, $input] = $this->clientFixture();
        $service = app(\App\Services\Portal\SubmitClientDetails::class);
        $first = $service->execute($client, $input, (string) Str::uuid());
        $this->actingAs($this->owner);
        $page = Livewire::test(EventContract::class, ['record' => $event->id])->assertSee('Fixture venue ONE');
        $service->execute($client, [...$input, 'expected_revision' => 1, 'event_address' => 'Fixture venue TWO'], (string) Str::uuid());
        $page->callAction('approve', ['travel_fee_confirmed' => true, 'setup_minutes' => 15, 'breakdown_minutes' => 15, 'review_note' => 'Owner reviewed only fixture venue ONE.'])
            ->assertSet('displayedSubmission.id', $first->id)->assertSee('Fixture venue ONE');
        $this->assertSame(0, ClientDetailsReview::count());
    }

    public function test_browser_cannot_replace_the_locked_approval_snapshot(): void
    {
        [$client, $event, $input] = $this->clientFixture();
        app(\App\Services\Portal\SubmitClientDetails::class)->execute($client, $input, (string) Str::uuid());
        $this->actingAs($this->owner);
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(EventContract::class, ['record' => $event->id])->set('displayedSubmission.id', 999999);
    }

    public function test_requested_local_time_uses_its_own_offset_on_the_event_date(): void
    {
        [$client, $event, $input] = $this->clientFixture('2026-11-01T00:30:00-05:00');
        $key = (string) Str::uuid();
        $input = [...$input, 'start_time_confirmed' => false, 'requested_local_time' => '03:30'];
        $response = $this->postJson('/portal/api/details', $input, ['Idempotency-Key' => $key])->assertCreated();
        $response->assertJsonPath('submission.data.requested_start_at', '2026-11-01T03:30:00-06:00');
        $this->postJson('/portal/api/details', $input, ['Idempotency-Key' => $key])->assertCreated()
            ->assertJsonPath('submission.id', $response->json('submission.id'));
        $this->assertSame('00:30', $event->fresh()->starts_at->setTimezone('America/Chicago')->format('H:i'));
        $this->assertArrayNotHasKey('requested_local_time', $response->json('submission.data'));
        $this->get('/portal')->assertSee('Recibimos tus datos');
    }

    public function test_repeated_requested_hour_requires_a_choice_and_preserves_both_occurrences(): void
    {
        [$client, $event, $input] = $this->clientFixture('2026-11-01T00:30:00-05:00');
        $input = [...$input, 'start_time_confirmed' => false, 'requested_local_time' => '01:30'];
        $this->postJson('/portal/api/details', $input, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('requested_time_choice');
        foreach (['cdt' => '-05:00', 'cst' => '-06:00'] as $choice => $offset) {
            $this->postJson('/portal/api/details', [...$input, 'expected_revision' => $choice === 'cdt' ? 0 : 1, 'requested_time_choice' => $choice], ['Idempotency-Key' => (string) Str::uuid()])
                ->assertCreated()->assertJsonPath('submission.data.requested_start_at', '2026-11-01T01:30:00'.$offset);
        }
    }

    public function test_nonexistent_requested_hour_is_refused_and_spring_offset_is_resolved(): void
    {
        [$client, $event, $input] = $this->clientFixture('2027-03-14T00:30:00-06:00');
        $input = [...$input, 'start_time_confirmed' => false];
        $this->postJson('/portal/api/details', [...$input, 'requested_local_time' => '02:30'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('requested_local_time');
        $this->assertSame(0, \App\Models\ClientDetailsSubmission::count());
        $this->postJson('/portal/api/details', [...$input, 'requested_local_time' => '03:30'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('submission.data.requested_start_at', '2027-03-14T03:30:00-05:00');
    }

    public function test_requested_time_rejects_missing_mixed_and_malformed_inputs(): void
    {
        [$client, $event, $input] = $this->clientFixture();
        $input = [...$input, 'start_time_confirmed' => false];
        foreach ([[], ['requested_local_time' => '25:00'], ['requested_local_time' => '16:00', 'requested_start_at' => '2026-12-19T16:00:00-06:00']] as $extra) {
            $this->postJson('/portal/api/details', [...$input, ...$extra], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->assertSame(0, \App\Models\ClientDetailsSubmission::count());
    }

    public function test_portal_shows_and_records_the_exact_configured_sms_wording(): void
    {
        [$client, $event, $input] = $this->clientFixture();
        $text = 'Fixture approved SMS wording <script>never execute</script> & no translation.';
        config(['waoo.sms_consent_versions' => ['fixture-approved-v2' => $text]]);
        foreach (['es', 'en'] as $lang) {
            $this->get('/portal?lang='.$lang)->assertOk()->assertSee($text)->assertDontSee('<script>never execute</script>', false)
                ->assertSee('data-version="fixture-approved-v2"', false);
        }
        $this->postJson('/portal/api/details', [...$input, 'sms_consent' => true, 'sms_consent_version' => 'fixture-approved-v2'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('submission.data.sms_consent_record.text', $text)
            ->assertJsonPath('submission.data.sms_consent_record.sha256', hash('sha256', $text));
    }


    // COMMS-01: "Copiar enlace" recovers the SAME active link (no new grant, same expiry, audited) and refuses inactive ones.
    public function test_copy_link_recovers_the_same_active_link_without_changes(): void
    {
        $qb = $this->quickBook(); $event = Event::sole(); $grant = PortalGrant::sole();
        $expires = $grant->expires_at->toIso8601String();
        $this->get("/admin/eventos/{$event->id}/contrato")->assertSee('Copiar enlace')->assertDontSee($qb->portal_url);
        $page = Livewire::test(EventContract::class, ['record' => $event->id])->callAction('copyLink', [], ['id' => $grant->id]);
        $this->assertSame($qb->portal_url, $page->get('newLink.url'));
        $page->assertSee('Enlace activo (el mismo de antes)')->assertSee('no es un número claro para enviar')->assertDontSee('beforeunload', false);
        $this->assertSame(1, PortalGrant::count());
        $this->assertSame($expires, $grant->fresh()->expires_at->toIso8601String());
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('audit_entries')->where('action', 'portal.link_revealed')->exists());

        app(\App\Services\Portal\RevokePortalGrant::class)->execute(User::sole(), $grant->id);
        Livewire::test(EventContract::class, ['record' => $event->id])->callAction('copyLink', [], ['id' => $grant->id])
            ->assertNotified('No se pudo copiar')->assertSet('newLink', null);
        $this->assertSame(1, PortalGrant::count(), 'Copy never issues a new link.');
    }

    public function test_legacy_link_without_encrypted_copy_shows_no_copy_button(): void
    {
        $this->quickBook(); $event = Event::sole();
        PortalGrant::query()->update(['encrypted_token' => null]);
        $this->get("/admin/eventos/{$event->id}/contrato")->assertDontSee('Copiar enlace')->assertSee('enlace anterior: no se puede volver a copiar');
    }
}
