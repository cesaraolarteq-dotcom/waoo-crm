<?php
namespace Tests\Feature;

use App\Exceptions\{RecordWriteRejected, ResponsibilityRejected};
use App\Models\{ClientDetailsReview, ClientDetailsSubmission, Contract, Customer, Event, LedgerEntry, PortalGrant, PriceVersion, ResourceReservation, ServiceVariant, User};
use App\Services\Booking\{ClientDetailsState, QuickBook};
use App\Services\Documents\{ActivateTemplateVersion, ArtifactStore, ClientSubmissionFingerprint, CreateTemplateVersion, PrepareClientContract};
use App\Services\Portal\{PortalContext, SubmitClientDetails};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue, Schema, Storage};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrepareClientContractTest extends TestCase
{
    use DatabaseMigrations;
    private PortalContext $client;
    private Event $event;
    private array $details;
    private array $input;

    protected function tearDown(): void
    {
        // Synthetic fixture cleanup only; production rollback must never invent client names.
        if (Schema::hasTable('customers')) DB::table('customers')->whereNull('name')->update(['name' => 'María González']);
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]);
        Storage::fake('local'); Queue::fake();
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $service = ServiceVariant::create(['name' => 'Celebración alegre', 'status' => 'active', 'duration_minutes' => 60,
            'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'entertainer']]]);
        $price = PriceVersion::create(['service_variant_id' => $service->id, 'amount_cents' => 20000, 'currency' => 'USD', 'created_by' => $owner->id]);
        $start = now()->addDays(20)->startOfHour();
        $booking = app(QuickBook::class)->execute($owner, [
            'new_customer' => ['phone' => '+17135550126', 'locale' => 'es'], 'title' => 'Cumpleaños de Sofía', 'event_type' => 'birthday',
            'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addHour()->toIso8601String(), 'timezone' => 'America/Chicago',
            'address' => '123 Example Lane', 'postal_code' => '77001', 'travel_fee_cents' => 0,
            'services' => [['price_version_id' => $price->id]], 'deposit' => ['method' => 'zelle', 'amount_cents' => 10000,
                'reference' => 'MARIAG-001', 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true]], (string) Str::uuid());
        $this->event = $booking->event;
        $template = app(CreateTemplateVersion::class)->execute($owner, ['terms_html' => '<p>Acuerdo ficticio de María González, solo para pruebas.</p>',
            'change_note' => 'Plantilla sintética para comprobar preparación del contrato.'], (string) Str::uuid());
        app(ActivateTemplateVersion::class)->execute($owner, $template->id, ['expected_source_sha256' => $template->source_sha256,
            'approval_note' => 'Aprobación ficticia para una prueba automatizada.']);
        $this->app['session.store']->put('portal_grant_id', PortalGrant::sole()->id);
        $request = Request::create('/portal/api/details', 'POST');
        $request->setLaravelSession($this->app['session.store']); $request->setUserResolver(fn () => null);
        $this->client = PortalContext::fromRequest($request);
        $this->details = ['expected_event_version' => $this->event->version, 'expected_revision' => 0,
            'first_name' => 'María', 'last_name' => 'González', 'phone' => '+17135550126', 'email' => 'maria@example.test',
            'billing_address' => '456 Example Road', 'event_address' => '123 Example Lane', 'event_postal_code' => '77001', 'start_time_confirmed' => true];
        app(SubmitClientDetails::class)->execute($this->client, $this->details, (string) Str::uuid());
        $this->input = ['expected_event_version' => $this->event->version, 'expected_revision' => 1];
    }

    private function prepare(?string $key = null): Contract
    {
        return app(PrepareClientContract::class)->execute($this->client, $this->input, $key ?? (string) Str::uuid());
    }

    public function test_preparation_freezes_submitted_identity_without_owner_review_or_operating_changes(): void
    {
        $before = $this->event->getRawOriginal(); $ledger = LedgerEntry::sole()->getRawOriginal();
        $contract = $this->prepare();
        $this->assertSame('issued', $contract->state); $this->assertNull($contract->issued_by);
        $this->assertSame('maria@example.test', $contract->signer_email);
        $this->assertSame('María', $contract->commercial_snapshot['client']['first_name']);
        $this->assertSame(ClientSubmissionFingerprint::capture(ClientDetailsSubmission::sole()), $contract->commercial_snapshot['client_details']);
        $pdf = app(ArtifactStore::class)->read($contract->presented_pdf_path, $contract->presented_pdf_sha256);
        $this->assertStringStartsWith('%PDF', $pdf);
        $html = app(ArtifactStore::class)->read($contract->presented_html_path, $contract->presented_html_sha256);
        $this->assertStringContainsString('María', $html); $this->assertStringContainsString('123 Example Lane', $html);
        $this->assertSame($before, $this->event->fresh()->getRawOriginal());
        $this->assertSame($ledger, LedgerEntry::sole()->getRawOriginal()); $this->assertNull(Customer::sole()->name);
        $this->assertSame(0, ClientDetailsReview::count()); $this->assertSame(0, ResourceReservation::count());
        $this->assertFalse(ClientDetailsState::portal($this->event)['reviewed']);
        $this->assertSame('review_details', ClientDetailsState::forEvent($this->event));
        $audit = DB::table('audit_entries')->where('action', 'contract.client_prepared')->sole();
        $this->assertNull($audit->actor_id); $this->assertStringNotContainsString('maria@example.test', $audit->after);
        $this->expectException(RecordWriteRejected::class);
        ClientDetailsState::assertApproved($this->event->id, $contract->financial_revision_id);
    }

    public function test_replays_and_new_uuid_share_one_immutable_document_without_new_audit(): void
    {
        $key = (string) Str::uuid(); $first = $this->prepare($key);
        $this->assertSame($first->id, $this->prepare($key)->id);
        $this->assertSame($first->id, $this->prepare()->id);
        $this->assertSame(1, Contract::count());
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'contract.client_prepared')->count());
        Storage::disk('local')->put($first->presented_pdf_path, 'corrupted synthetic artifact');
        $this->expectException(\RuntimeException::class); $this->prepare($key);
    }

    public static function changedAgreement(): array
    {
        return [['address'], ['zip'], ['requested_time'], ['customer'], ['event_version'], ['receipt'], ['retired_template'], ['responsibility'], ['scope'], ['revoked']];
    }

    #[DataProvider('changedAgreement')]
    public function test_ineligible_source_never_issues_and_keeps_the_submission(string $change): void
    {
        $row = ClientDetailsSubmission::sole();
        if (in_array($change, ['address','zip','requested_time'])) {
            $data = $row->data;
            if ($change === 'address') $data['event_address'] = '789 Other Road';
            if ($change === 'zip') $data['event_postal_code'] = '77002';
            if ($change === 'requested_time') { $data['start_time_confirmed'] = false; $data['requested_start_at'] = now()->addDays(20)->toIso8601String(); }
            DB::table('client_details_submissions')->where('id', $row->id)->update(['data' => json_encode($data)]);
        }
        if ($change === 'customer') DB::table('customers')->update(['phone' => '+17135550127']);
        if ($change === 'event_version') DB::table('events')->increment('version');
        if ($change === 'receipt') DB::table('ledger_entries')->update(['verified_by' => null]);
        if ($change === 'retired_template') DB::table('contract_template_versions')->update(['state' => 'retired']);
        if ($change === 'responsibility') DB::table('events')->update(['responsible_system' => 'honeybook']);
        if ($change === 'scope') DB::table('portal_grants')->update(['scopes' => json_encode(['details.submit','contract.read'])]);
        if ($change === 'revoked') DB::table('portal_grants')->update(['revoked_at' => now()]);
        try { $this->prepare(); $this->fail('An ineligible source issued a contract.'); }
        catch (RecordWriteRejected|AuthorizationException|ResponsibilityRejected $error) { $this->assertNotEmpty($error->getMessage()); }
        $this->assertSame(0, Contract::count()); $this->assertSame(1, ClientDetailsSubmission::count());
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_storage_failure_does_not_discard_details_and_retry_can_prepare(): void
    {
        Storage::shouldReceive('disk')->with('local')->andReturnSelf();
        Storage::shouldReceive('exists')->andReturn(false); Storage::shouldReceive('put')->andReturn(false);
        $key = (string) Str::uuid();
        try { $this->prepare($key); $this->fail('Storage failure was ignored.'); }
        catch (\RuntimeException $e) { $this->assertSame('Private artifact storage failed.', $e->getMessage()); }
        $this->assertSame(0, Contract::count()); $this->assertSame(1, ClientDetailsSubmission::count());
        Storage::clearResolvedInstance('filesystem'); $this->app->forgetInstance('filesystem'); Storage::fake('local');
        $this->assertSame('issued', $this->prepare($key)->state);
    }

    public function test_newer_submission_supersedes_only_unsigned_document_and_signed_state_is_not_owner_review(): void
    {
        $first = $this->prepare();
        app(SubmitClientDetails::class)->execute($this->client, [...$this->details, 'expected_revision' => 1, 'first_name' => 'María Elena'], (string) Str::uuid());
        $this->input['expected_revision'] = 2;
        $second = $this->prepare(); $this->assertSame('superseded', $first->fresh()->state);
        $this->assertSame('María Elena', $second->commercial_snapshot['client']['first_name']);
        $second->fill(['state' => 'signed', 'signed_at' => now()])->save();
        $this->assertFalse(ClientDetailsState::portal($this->event)['reviewed']);
        $this->expectException(RecordWriteRejected::class); $this->prepare();
    }

    public function test_fingerprint_is_versioned_closed_and_sensitive_to_actual_submitted_identity(): void
    {
        $row = ClientDetailsSubmission::sole(); $fingerprint = ClientSubmissionFingerprint::capture($row);
        $row->setAttribute('future_model_field', 'irrelevant'); $data = $row->data; $data['future_question'] = 'irrelevant'; $row->data = $data;
        $this->assertSame($fingerprint, ClientSubmissionFingerprint::capture($row));
        $data['first_name'] = 'Lucía'; $row->data = $data;
        $this->assertNotSame($fingerprint['submission_sha256'], ClientSubmissionFingerprint::capture($row)['submission_sha256']);
        $this->assertSame(['schema_version','submission_id','submission_revision','submission_sha256'], array_keys($fingerprint));
    }

    public function test_audit_failure_rolls_back_document_but_preserves_saved_details_and_can_retry(): void
    {
        $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && str_starts_with(strtolower($query->sql), 'insert into ') && str_contains(strtolower($query->sql), 'audit_entries')
                && in_array('contract.client_prepared', $query->bindings, true)) throw new \RuntimeException('Synthetic preparation audit failure');
        });
        $key = (string) Str::uuid();
        try { $this->prepare($key); $this->fail('Unaudited contract was committed.'); }
        catch (\RuntimeException $error) { $this->assertSame('Synthetic preparation audit failure', $error->getMessage()); }
        $this->assertSame(0, Contract::count()); $this->assertSame(1, ClientDetailsSubmission::count());
        $fail = false; $this->assertSame('issued', $this->prepare($key)->state);
    }

    public function test_admin_session_cannot_prepare_even_with_customer_grant(): void
    {
        $request = Request::create('/portal/api/details', 'POST'); $request->setLaravelSession($this->app['session.store']);
        $request->setUserResolver(fn () => User::sole());
        $this->client = PortalContext::fromRequest($request);
        $this->expectException(AuthorizationException::class); $this->prepare();
    }

    public function test_retry_rechecks_expired_grant_and_newer_submission_instead_of_reusing_old_contract(): void
    {
        $key = (string) Str::uuid(); $first = $this->prepare($key);
        app(SubmitClientDetails::class)->execute($this->client, [...$this->details, 'expected_revision' => 1, 'first_name' => 'Lucía'], (string) Str::uuid());
        try { $this->prepare($key); $this->fail('Replayed stale submission.'); }
        catch (RecordWriteRejected $error) { $this->assertSame(409, $error->status); }
        $this->assertSame('issued', $first->fresh()->state); $this->assertSame(1, Contract::count());
        $this->input['expected_revision'] = 2;
        DB::table('portal_grants')->update(['expires_at' => now()->subMinute()]);
        $this->expectException(AuthorizationException::class); $this->prepare();
    }
}
