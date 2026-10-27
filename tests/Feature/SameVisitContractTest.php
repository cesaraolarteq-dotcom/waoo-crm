<?php
namespace Tests\Feature;

use App\Models\{ClientDetailsReview, ClientDetailsSubmission, Contract, Customer, Event, LedgerEntry, PortalGrant, PriceVersion, ResourceReservation, ServiceVariant, SignatureEvidence, User};
use App\Services\Booking\QuickBook;
use App\Services\Documents\{ActivateTemplateVersion, CreateTemplateVersion, InHouseSignatureProvider};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Log, Queue, Schema, Storage};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SameVisitContractTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private Event $event;
    private array $details;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true,
            'waoo.sandbox_immediate_contracts' => true, 'app.url' => 'http://localhost']);
        Queue::fake(); Storage::fake('local');
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $service = ServiceVariant::create(['name' => 'Celebración alegre', 'status' => 'active', 'duration_minutes' => 60,
            'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'entertainer']]]);
        $price = PriceVersion::create(['service_variant_id' => $service->id, 'amount_cents' => 20000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = now()->addDays(20)->startOfHour();
        $booking = app(QuickBook::class)->execute($this->owner, [
            'new_customer' => ['phone' => '+17135550126', 'locale' => 'es'], 'title' => 'Cumpleaños de Sofía', 'event_type' => 'birthday',
            'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago',
            'address' => '123 Example Lane', 'postal_code' => '77001', 'travel_fee_cents' => 0, 'setup_minutes' => 0, 'breakdown_minutes' => 0,
            'services' => [['price_version_id' => $price->id]], 'deposit' => ['method' => 'zelle', 'amount_cents' => 10000,
                'reference' => 'MARIAG-001', 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true]], (string) Str::uuid());
        $this->event = $booking->event;
        $template = app(CreateTemplateVersion::class)->execute($this->owner, ['terms_html' => '<p>Acuerdo ficticio, solo para pruebas.</p>',
            'change_note' => 'Plantilla sintética para la visita de María.'], (string) Str::uuid());
        app(ActivateTemplateVersion::class)->execute($this->owner, $template->id, ['expected_source_sha256' => $template->source_sha256,
            'approval_note' => 'Aprobación ficticia para prueba automatizada.']);
        parse_str(parse_url($booking->portal_url, PHP_URL_FRAGMENT), $fragment);
        $this->postJson('/portal/api/session', ['token' => $fragment['token']])->assertOk();
        $this->details = ['expected_event_version' => $this->event->version, 'expected_revision' => 0,
            'first_name' => 'María', 'last_name' => 'González', 'phone' => '+17135550126', 'email' => 'maria@example.test',
            'billing_address' => '456 Example Road', 'event_address' => '123 Example Lane', 'event_postal_code' => '77001', 'start_time_confirmed' => true];
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('customers')) DB::table('customers')->whereNull('name')->update(['name' => 'María González']);
        if(Schema::hasColumn('events','billing_company')) DB::table('events')->update(['billing_company'=>null,'billing_company_version'=>0]);
        parent::tearDown();
    }

    private function submit(?string $key = null, array $extra = [])
    {
        return $this->postJson('/portal/api/details', [...$this->details, ...$extra], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function retry(?string $key = null, array $extra = [])
    {
        return $this->postJson('/portal/api/details/contract', ['expected_event_version' => $this->event->version,
            'expected_revision' => ClientDetailsSubmission::max('revision') ?? 1, ...$extra], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function sign(Contract $contract, ?string $key = null, array $extra = [])
    {
        return $this->postJson('/portal/api/contracts/'.$contract->id.'/sign', ['expected_version' => $contract->version,
            'presented_pdf_sha256' => $contract->presented_pdf_sha256, 'signer_name' => 'María González',
            'consent' => true, 'intent_to_sign' => true, 'consent_version' => 'esign-consent-v2-2026-10-20-es', ...$extra], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_company_is_frozen_in_the_same_visit_and_person_remains_the_signer(): void
    {
        config(['waoo.sandbox_company'=>true]);
        $this->get('/portal?lang=es')->assertOk()->assertSee('Es para una empresa');
        $this->get('/portal?lang=en')->assertOk()->assertSee('This is for a company');
        $this->submit(extra:['billing_company'=>['name'=>'Celebraciones del Sol','email'=>'billing@example.test']])->assertCreated()->assertJsonPath('contract_preparation.status','ready');
        $contract=Contract::sole();
        $this->assertSame('Celebraciones del Sol',$contract->commercial_snapshot['billing_company']['name']);
        $this->assertSame(2,$contract->commercial_snapshot['client_details']['schema_version']);
        $beforeVersion=$this->event->version;
        $this->get('/portal/api/contracts/'.$contract->id.'/pdf')->assertOk();
        $this->sign($contract)->assertOk();
        $this->assertSame('María González',SignatureEvidence::sole()->signer_name);
        $this->assertSame('Celebraciones del Sol',$this->event->fresh()->billing_company['name']);
        $this->assertSame($beforeVersion,$this->event->fresh()->version);
        $this->assertDatabaseCount('ledger_entries',1);
        $this->assertSame(1,SignatureEvidence::count());
    }

    public function test_one_actual_client_session_submits_opens_exact_pdf_signs_and_continues_without_owner_review(): void
    {
        $before = $this->event->getRawOriginal(); $receipt = LedgerEntry::sole()->getRawOriginal();
        $this->get('/portal?lang=es')->assertOk()->assertSee('id="details-form"', false);
        $key = (string) Str::uuid();
        $response = $this->submit($key)->assertCreated()->assertJsonPath('reviewed', false)->assertJsonPath('contract_preparation.status', 'ready');
        $contract = Contract::sole();
        $this->assertSame($contract->id, $response->json('contract_preparation.contract.id'));
        $this->submit($key)->assertCreated()->assertJsonPath('contract_preparation.contract.id', $contract->id);
        $this->get('/portal?lang=es')->assertOk()->assertSee('Tu contrato')->assertSee('id="sign-form"', false)->assertDontSee('id="details-form"', false);
        $this->get('/portal?lang=en')->assertOk()->assertSee('Your contract');
        $this->sign($contract)->assertUnprocessable();
        $this->get('/portal/api/contracts/'.$contract->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->sign($contract, extra: ['consent' => false])->assertUnprocessable();
        $this->sign($contract, extra: ['intent_to_sign' => false])->assertUnprocessable();
        $this->sign($contract, extra: ['consent_version' => 'esign-consent-v1'])->assertUnprocessable();
        $signKey = (string) Str::uuid(); $this->sign($contract, $signKey)->assertOk();
        $this->sign($contract, $signKey)->assertOk();
        config(['waoo.sandbox_immediate_contracts' => false]);
        $this->sign($contract, $signKey)->assertOk(); // Historical accepted replay never rewrites evidence.
        $this->sign($contract)->assertForbidden();
        $this->assertSame(1, Contract::count()); $this->assertSame(1, SignatureEvidence::count());
        $this->assertSame(0, ClientDetailsReview::count()); $this->assertNull($contract->fresh()->issued_by);
        $evidence = SignatureEvidence::sole();
        $this->assertSame('maria@example.test', $evidence->signer_email);
        $this->assertSame('scoped_link_email_unverified', $evidence->identity_method);
        $consent = InHouseSignatureProvider::consent('es');
        $this->assertSame($consent['text']."\n".$consent['intent'], $evidence->consent_text);
        $this->assertSame($contract->presented_pdf_sha256, $evidence->presented_pdf_sha256);
        Queue::assertPushed(\App\Jobs\FinalizeSignedContract::class, 1);
        (new \App\Jobs\FinalizeSignedContract($contract->id))->handle();
        $this->get('/portal/api/contracts/'.$contract->id.'/pdf?artifact=final')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertNotNull($contract->fresh()->final_pdf_sha256);
        $this->assertSame($contract->presented_pdf_sha256, $contract->fresh()->presented_pdf_sha256);
        $this->getJson('/portal/api/event')->assertOk()->assertJsonPath('details.reviewed', false);
        $this->get('/portal?lang=es')->assertOk()->assertSee('Saldo pendiente')->assertSee('Pagar el saldo')->assertSee('Ver mi contrato firmado')->assertSee('Descargar (PDF)')->assertDontSee('id="sign-form"', false);
        $this->submit(extra: ['expected_revision' => 1])->assertConflict();
        $this->assertSame($before, $this->event->fresh()->getRawOriginal()); $this->assertSame($receipt, LedgerEntry::sole()->getRawOriginal());
        $this->assertNull(Customer::sole()->name); $this->assertSame(0, ResourceReservation::count());
        $this->actingAs($this->owner); Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get('/admin/eventos/'.$this->event->id.'/contrato')->assertOk()->assertSee('Firmado')->assertSee('Recibidos · sin revisión del dueño');
    }

    public function test_feature_off_preserves_legacy_response_and_hides_retry_without_generating_on_get(): void
    {
        config(['waoo.sandbox_immediate_contracts' => false]);
        $this->submit()->assertCreated()->assertJsonMissingPath('contract_preparation');
        $this->retry()->assertNotFound(); $this->get('/portal')->assertOk()->assertSee('Recibimos tus datos')->assertDontSee('id="prepare-contract-form"', false);
        $this->assertSame(0, Contract::count());
        config(['waoo.sandbox_immediate_contracts' => true]);
        $this->get('/portal')->assertOk()->assertSee('id="prepare-contract-form"', false);
        $this->assertSame(0, Contract::count());
        $this->retry()->assertOk()->assertJsonPath('contract_preparation.status', 'ready');
    }

    public function test_unapproved_template_and_changed_address_keep_saved_details_for_review(): void
    {
        DB::table('contract_template_versions')->update(['state' => 'retired']);
        $this->submit()->assertCreated()->assertJsonPath('contract_preparation.status', 'review_required');
        $this->retry()->assertConflict();
        $this->assertSame(1, ClientDetailsSubmission::count()); $this->assertSame(0, Contract::count());
        $this->submit(extra: ['expected_revision' => 1, 'event_address' => '789 Other Road'])->assertCreated()->assertJsonPath('contract_preparation.status', 'review_required');
        $this->assertSame('123 Example Lane', $this->event->fresh()->address);
    }

    public function test_storage_failure_and_generic_retry_preserve_details_and_stable_uuid(): void
    {
        Log::spy(); Storage::shouldReceive('disk')->with('local')->andReturnSelf();
        Storage::shouldReceive('exists')->andReturn(false); Storage::shouldReceive('put')->andReturn(false);
        $this->submit()->assertCreated()->assertJsonPath('contract_preparation.status', 'retry_required');
        $key = (string) Str::uuid(); $this->retry($key)->assertStatus(503)->assertDontSee('Private artifact');
        $this->assertSame(1, ClientDetailsSubmission::count()); $this->assertSame(0, Contract::count());
        Log::shouldNotHaveReceived('error');
        Storage::clearResolvedInstance('filesystem'); $this->app->forgetInstance('filesystem'); Storage::fake('local');
        $this->retry($key)->assertOk()->assertJsonPath('contract_preparation.status', 'ready');
        $this->retry($key)->assertOk(); $this->assertSame(1, Contract::count()); $this->assertSame(1, ClientDetailsSubmission::count());
    }

    public static function staleSources(): array
    {
        return [['event'], ['customer'], ['submission'], ['provenance'], ['amount'], ['scope'], ['revoked'], ['feature_off'], ['no_provenance']];
    }

    #[DataProvider('staleSources')]
    public function test_signature_rechecks_source_and_cannot_turn_missing_provenance_into_owner_approval(string $change): void
    {
        $this->submit()->assertCreated()->assertJsonPath('contract_preparation.status', 'ready'); $contract = Contract::sole();
        $this->get('/portal/api/contracts/'.$contract->id.'/pdf')->assertOk();
        if ($change === 'event') DB::table('events')->increment('version');
        if ($change === 'customer') DB::table('customers')->update(['email' => 'lucia@example.test']);
        if ($change === 'submission') $this->submit(extra: ['expected_revision' => 1, 'first_name' => 'Lucía'])->assertCreated();
        if (in_array($change, ['provenance','amount','no_provenance'])) {
            $snapshot = $contract->commercial_snapshot;
            if ($change === 'provenance') $snapshot['client_details']['submission_sha256'] = str_repeat('0', 64);
            if ($change === 'amount') $snapshot['total_cents']++;
            if ($change === 'no_provenance') unset($snapshot['client_details']);
            DB::table('contracts')->where('id', $contract->id)->update(['commercial_snapshot' => json_encode($snapshot)]);
        }
        if ($change === 'scope') DB::table('portal_grants')->update(['scopes' => json_encode(['payments.read','contract.read','contract.sign'])]);
        if ($change === 'revoked') DB::table('portal_grants')->update(['revoked_at' => now()]);
        if ($change === 'feature_off') config(['waoo.sandbox_immediate_contracts' => false]);
        $this->sign($contract)->assertStatus(in_array($change, ['scope','revoked','feature_off']) ? 403 : 409);
        $this->assertSame(0, SignatureEvidence::count()); $this->assertSame(0, ClientDetailsReview::count());
    }

    public function test_readonly_agreed_fields_and_retry_versions_are_fixed_in_actual_portal_html(): void
    {
        $page = $this->get('/portal')->assertOk(); $dom = new \DOMDocument(); @$dom->loadHTML($page->getContent()); $xpath = new \DOMXPath($dom);
        foreach (['event_address' => '123 Example Lane', 'event_postal_code' => '77001'] as $name => $value) {
            $field = $xpath->query('//input[@name="'.$name.'"]')->item(0); $this->assertTrue($field->hasAttribute('readonly')); $this->assertSame($value, $field->getAttribute('value'));
        }
        DB::table('contract_template_versions')->update(['state' => 'retired']);
        $this->submit()->assertCreated();
        $this->get('/portal')->assertOk()->assertSee('id="prepare-contract-form"', false)->assertSee('data-revision="1"', false);
        $this->retry(extra: ['expected_revision' => 0])->assertUnprocessable();
        $this->retry(extra: ['expected_revision' => 2])->assertConflict();
        $this->retry(extra: ['total_cents' => 1])->assertUnprocessable();
    }

    public function test_retry_requires_real_staging_csrf_https_and_secure_session_cookie(): void
    {
        config(['waoo.sandbox_immediate_contracts' => false]); $this->submit()->assertCreated();
        config(['waoo.sandbox_immediate_contracts' => true, 'session.secure' => true]);
        $this->app->instance('env', 'staging');
        $input = ['expected_event_version' => $this->event->version, 'expected_revision' => 1];
        $key = (string) Str::uuid();
        $this->withSession(['_token' => 'synthetic-retry-csrf'])->postJson('https://localhost/portal/api/details/contract', $input,
            ['Idempotency-Key' => $key])->assertStatus(419);
        $headers = ['Idempotency-Key' => $key, 'X-CSRF-TOKEN' => 'synthetic-retry-csrf'];
        $this->postJson('http://localhost/portal/api/details/contract', $input, $headers)->assertForbidden();
        config(['session.secure' => false]);
        $this->postJson('https://localhost/portal/api/details/contract', $input, $headers)->assertForbidden();
        config(['session.secure' => true]);
        $this->postJson('https://localhost/portal/api/details/contract', $input, $headers)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_admin_session_and_other_customer_grant_cannot_prepare_or_sign_for_this_customer(): void
    {
        $this->submit()->assertCreated(); $contract = Contract::sole();
        $this->get('/portal/api/contracts/'.$contract->id.'/pdf')->assertOk();
        $this->actingAs($this->owner); $this->retry()->assertForbidden(); $this->sign($contract)->assertForbidden();
        auth()->logout();
        $other = Customer::create(['name' => 'Lucía Pérez', 'email' => 'lucia@example.test', 'phone' => '+17135550127', 'locale' => 'es']);
        // Corrupt/misbound grants must not authorize this customer's artifacts or writes.
        DB::table('portal_grants')->update(['customer_id' => $other->id]);
        $this->get('/portal/api/contracts/'.$contract->id.'/pdf')->assertNotFound();
        $this->retry()->assertConflict(); $this->sign($contract)->assertNotFound();
        $this->assertSame(0, SignatureEvidence::count()); $this->assertSame(1, ClientDetailsSubmission::count());
    }

    public function test_audit_failure_in_auto_prepare_saves_details_and_retry_does_not_duplicate_submission(): void
    {
        $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && str_starts_with(strtolower($query->sql), 'insert into ') && str_contains(strtolower($query->sql), 'audit_entries')
                && in_array('contract.client_prepared', $query->bindings, true)) throw new \RuntimeException('Synthetic private audit failure');
        });
        $this->submit()->assertCreated()->assertJsonPath('contract_preparation.status', 'retry_required')->assertDontSee('Synthetic private');
        $this->assertSame(1, ClientDetailsSubmission::count()); $this->assertSame(0, Contract::count());
        $fail = false; $this->retry()->assertOk(); $this->assertSame(1, ClientDetailsSubmission::count()); $this->assertSame(1, Contract::count());
    }

    public function test_explicit_client_correction_reissues_only_unsigned_document_and_signed_query_cannot_reopen_details(): void
    {
        $this->submit()->assertCreated(); $first = Contract::sole();
        $this->get('/portal?review_details=1&lang=es')->assertOk()->assertSee('id="details-form"', false)
            ->assertSee('data-revision="1"', false)->assertSee('Para cambiar la dirección o el ZIP');
        $this->assertSame(1, ClientDetailsSubmission::count()); $this->assertSame(1, Contract::count());
        $this->submit(extra: ['expected_revision' => 1, 'first_name' => 'María Elena'])->assertCreated()->assertJsonPath('contract_preparation.status', 'ready');
        $this->assertSame('superseded', $first->fresh()->state);
        $current = Contract::where('state', 'issued')->sole();
        $this->assertSame('María Elena', $current->commercial_snapshot['client']['first_name']);
        $this->get('/portal')->assertOk()->assertSee('id="sign-form"', false)->assertDontSee('id="details-form"', false);
        $this->get('/portal/api/contracts/'.$current->id.'/pdf')->assertOk(); $this->sign($current)->assertOk();
        // A signed contract cannot expose a final-download link until its queued PDF artifact actually exists.
        $this->get('/portal?review_details=1')->assertOk()->assertSee('Saldo pendiente')->assertSee('Tu depósito ya está pagado.')
            ->assertDontSee('Ver mi contrato firmado')->assertDontSee('id="details-form"', false)->assertDontSee('Tu fecha ya está apartada');
        (new \App\Jobs\FinalizeSignedContract($current->id))->handle();
        $this->get('/portal?review_details=1')->assertOk()->assertSee('Ver mi contrato firmado')->assertSee('Descargar (PDF)')
            ->assertDontSee('id="details-form"', false);
    }

    public function test_capacity_confirmation_mid_visit_requires_explicit_fresh_submission_and_preserves_assigned_staff(): void
    {
        $this->submit()->assertCreated(); $first = Contract::sole();
        $staff = \App\Models\Resource::create(['code' => 'LUCIA-01', 'name' => 'Lucía Pérez', 'kind' => 'staff', 'status' => 'active', 'skills' => ['entertainer','driver']]);
        $this->event = app(\App\Services\Finance\ConfirmPaidCapacity::class)->execute($this->owner, $this->event->id,
            ['expected_version' => $this->event->version, 'financial_revision_id' => $first->financial_revision_id, 'resource_ids' => [$staff->id],
                'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Se revisó el traslado del equipo para esta fiesta sintética.', 'transfers' => []]]);
        $this->sign($first)->assertConflict(); $this->retry()->assertConflict();
        $this->get('/portal')->assertOk()->assertSee('Revisar mis datos')->assertDontSee('id="sign-form"', false);
        $this->get('/portal?review_details=1')->assertOk()->assertSee('id="details-form"', false)
            ->assertSee('data-event-version="'.$this->event->version.'"', false)->assertSee('data-revision="1"', false);
        $this->assertSame(1, ClientDetailsSubmission::count());
        $this->submit(extra: ['expected_event_version' => $this->event->version, 'expected_revision' => 1])->assertCreated()->assertJsonPath('contract_preparation.status', 'ready');
        $current = Contract::where('state', 'issued')->sole();
        $this->get('/portal/api/contracts/'.$current->id.'/pdf')->assertOk(); $this->sign($current)->assertOk();
        $this->assertSame('capacity_reserved', $this->event->fresh()->status);
        $this->assertSame([$staff->id], ResourceReservation::where('event_id', $this->event->id)->pluck('resource_id')->all());
        $this->assertSame(1, LedgerEntry::count()); $this->assertSame(0, ClientDetailsReview::count());
    }
}
