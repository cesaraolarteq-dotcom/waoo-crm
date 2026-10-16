<?php
namespace Tests\Feature;

use App\Filament\Pages\QuickBooking as QuickBookingPage;
use App\Models\{ClientDetailsReview, ClientDetailsSubmission, Contract, Customer, Event, FinancialRevision, LedgerEntry, PortalGrant, PriceVersion, QuickBookingRecord, ResourceReservation, ServiceVariant, User};
use App\Services\Booking\{ApproveClientDetails, QuickBook};
use App\Services\Documents\{ActivateTemplateVersion, CreateContract, CreateTemplateVersion, IssueContract};
use App\Services\Portal\IssuePortalGrant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class QuickBookingCoreTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]);
        Queue::fake(); Storage::fake('local');
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant = ServiceVariant::create(['name' => 'Fixture Quick Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = now()->addDays(20)->startOfHour();
        $this->input = ['new_customer' => ['name' => 'Provisional Fixture', 'phone' => '555-0100', 'email' => null, 'locale' => 'es'],
            'title' => 'Fixture Quick Party', 'event_type' => 'birthday', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago',
            'postal_code' => '00001', 'travel_fee_cents' => 2500, 'setup_minutes' => 30, 'breakdown_minutes' => 20,
            'services' => [['price_version_id' => $price->id]],
            'deposit' => ['method' => 'zelle', 'amount_cents' => 6200, 'reference' => 'QB-FIXTURE-1', 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true],
            'portal' => ['expires_at' => now()->addDays(10)->toIso8601String()]];
    }

    private function create(?array $input = null, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/admin/api/quick-bookings', $input ?? $this->input);
    }

    private function client(): Event
    {
        $response = $this->create()->assertCreated();
        parse_str(parse_url($response->json('portal_url'), PHP_URL_FRAGMENT), $fragment);
        auth()->logout();
        $this->postJson('/portal/api/session', ['token' => $fragment['token']])->assertOk();
        return Event::sole();
    }

    private function details(array $over = []): array
    {
        return [...['expected_event_version' => Event::sole()->version, 'expected_revision' => 0,
            'first_name' => 'Fixture', 'last_name' => 'Client', 'phone' => '+15555550123', 'email' => 'client@example.test',
            'billing_address' => '123 Fictitious Billing', 'event_address' => '456 Fictitious Venue', 'event_postal_code' => '00001',
            'start_time_confirmed' => true, 'child_name' => 'Fictitious Child', 'theme' => 'Fixture theme', 'sms_consent' => false], ...$over];
    }

    private function submit(array $over = [], ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/portal/api/details', $this->details($over));
    }

    private function approve(array $over = [], ?string $key = null)
    {
        $this->actingAs($this->owner);
        $event = Event::sole(); $submission = ClientDetailsSubmission::latest('revision')->firstOrFail();
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/admin/api/events/'.$event->id.'/client-details/'.$submission->id.'/approve', [
            'expected_event_version' => $event->version, 'expected_revision' => $submission->revision, 'travel_fee_confirmed' => true,
            'review_note' => 'Reviewed fictitious venue and the quoted travel fee.', ...$over,
        ]);
    }

    private function issue(): Contract
    {
        $this->actingAs($this->owner);
        $event = Event::sole(); $finance = FinancialRevision::latest('revision')->firstOrFail();
        $template = app(CreateTemplateVersion::class)->execute($this->owner, ['terms_html' => '<p>Fixture agreement only; not the Waoo contract.</p>', 'change_note' => 'Fictitious quick-flow test'], (string) Str::uuid());
        app(ActivateTemplateVersion::class)->execute($this->owner, $template->id, ['expected_source_sha256' => $template->source_sha256, 'approval_note' => 'Fixture approval only, never real legal text.']);
        $contract = app(CreateContract::class)->execute($this->owner, $event->id, ['expected_version' => $event->version, 'financial_revision_id' => $finance->id, 'template_version_id' => $template->id,
            'client' => ['first_name' => 'Fixture', 'last_name' => 'Client', 'phone' => '+15555550123', 'email' => 'client@example.test', 'address' => '123 Fictitious Billing']], (string) Str::uuid());
        return app(IssueContract::class)->execute($this->owner, $contract->id, ['expected_version' => 1], (string) Str::uuid());
    }

    public function test_verified_core_commits_once_and_replay_never_reveals_another_secret(): void
    {
        $key = (string) Str::uuid();
        $first = $this->create(key: $key)->assertCreated()->assertJsonPath('finance.received_cents', 6200)->assertJsonPath('finance.deposit_shortfall_cents', 50);
        $this->assertNotNull($first->json('portal_url'));
        $this->assertSame('draft', Event::sole()->status);
        $this->create(key: $key)->assertCreated()->assertJsonPath('portal_url', null)->assertJsonPath('portal_link_already_shown', true);
        $this->create([...$this->input, 'title' => 'Different payload'], $key)->assertConflict();
        foreach (['customers','events','financial_revisions','ledger_entries','quick_bookings','portal_grants'] as $table) $this->assertDatabaseCount($table, 1);
        $this->assertDatabaseCount('resource_reservations', 0);
        $this->assertStringNotContainsString($first->json('portal_url'), json_encode(DB::table('audit_entries')->get()));
        $this->assertStringNotContainsString($first->json('portal_url'), json_encode(DB::table('mutation_receipts')->get()));
    }

    public function test_duplicate_reference_and_invalid_core_input_do_not_leave_another_customer_or_party(): void
    {
        $this->create()->assertCreated();
        $this->create([...$this->input, 'title' => 'Second party'])->assertConflict();
        $this->create([...$this->input, 'travel_fee_cents' => null])->assertUnprocessable();
        foreach (['customers','events','financial_revisions','ledger_entries','quick_bookings'] as $table) $this->assertDatabaseCount($table, 1);
    }

    public function test_failed_grant_preserves_payment_and_owner_can_recover_without_another_deposit(): void
    {
        $issuer = app(IssuePortalGrant::class);
        $this->app->instance(IssuePortalGrant::class, new class { public function execute(...$args) { throw new \RuntimeException('Fixture grant failure'); } });
        $key = (string) Str::uuid();
        $this->create(key: $key)->assertCreated()->assertJsonPath('portal_url', null)->assertJsonPath('link_issue_pending', true);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertDatabaseHas('payment_exceptions', ['kind' => 'portal_link_pending', 'state' => 'open']);
        $this->create(key: $key)->assertCreated()->assertJsonPath('link_issue_pending', true);
        $this->app->instance(IssuePortalGrant::class, $issuer);
        $event = Event::sole();
        $this->postJson('/admin/api/events/'.$event->id.'/portal-grants', ['customer_id' => $event->customer_id, 'expires_at' => now()->addDay()->toIso8601String(), 'scopes' => ['details.submit','contract.read','contract.sign','payments.read','checkout.create']])->assertCreated();
        $this->assertDatabaseHas('payment_exceptions', ['kind' => 'portal_link_pending', 'state' => 'resolved']);
        $this->assertNotNull(QuickBookingRecord::sole()->portal_grant_id);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_two_processes_with_one_key_create_one_party_payment_and_link(): void
    {
        $owner = $this->owner; $input = $this->input; $key = (string) Str::uuid();
        $worker = function () use ($owner, $input, $key) { $result = app(QuickBook::class)->execute($owner, $input, $key); return $result->portal_url ? 'link' : 'replay'; };
        $results = ConcurrentWorkers::run([$worker, $worker]); sort($results);
        $this->assertSame(['link','replay'], $results);
        foreach (['customers','events','ledger_entries','portal_grants','quick_bookings'] as $table) $this->assertDatabaseCount($table, 1);
    }

    public function test_disabled_sandbox_non_owner_and_enclosing_transaction_are_rejected_before_writes(): void
    {
        config(['waoo.sandbox_documents' => false]); $this->create()->assertForbidden();
        config(['waoo.sandbox_documents' => true]);
        $staff = User::factory()->create(['role' => 'staff', 'active' => true]); $this->actingAs($staff); $this->create()->assertForbidden();
        $this->actingAs($this->owner);
        DB::beginTransaction();
        try { app(QuickBook::class)->execute($this->owner, $this->input, (string) Str::uuid()); $this->fail('Enclosing transaction accepted.'); }
        catch (\App\Exceptions\RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        finally { DB::rollBack(); }
        $this->assertDatabaseCount('events', 0);
    }

    public function test_real_quick_booking_page_uses_service_and_never_promises_assigned_capacity(): void
    {
        // Freeze before the fall DST change: 30 Houston calendar days exceed 30 UTC days by one hour.
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-15 12:00:00', 'America/Chicago'));
        try {
        $price = PriceVersion::sole();
        Livewire::test(QuickBookingPage::class)->fillForm(['price_version_ids' => [$price->id], 'event_type' => 'birthday', 'title' => 'Fixture UI Party',
            'date' => '2026-12-19', 'start_time' => '15:00', 'postal_code' => '00001', 'travel_fee' => '25', 'customer_mode' => 'new', 'name' => 'Fixture Contact', 'locale' => 'es',
            'deposit' => ['amount' => '62', 'reference' => 'QB-REAL-UI-FIXTURE', 'date' => now('America/Chicago')->format('Y-m-d'), 'time' => '00:05', 'verified' => true], 'expires_days' => 30])
            ->call('save')->assertHasNoFormErrors()->assertSee('solo se muestra esta vez')->assertDontSee('is held')->assertDontSee('quedó apartada');
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('portal_grants', 1);
        $this->assertSame('draft', Event::sole()->status);
        } finally { $this->travelBack(); }
    }

    public function test_submission_is_scoped_immutable_and_does_not_change_contact_time_or_money(): void
    {
        $event = $this->client(); $oldStart = $event->starts_at;
        $this->getJson('/portal/api/event')->assertJsonPath('details.expected_revision', 0)->assertJsonPath('details.expected_event_version', 1);
        $key = (string) Str::uuid();
        $this->submit(key: $key)->assertCreated()->assertJsonPath('submission.revision', 1);
        $this->submit(key: $key)->assertCreated();
        $this->submit(['last_name' => 'Different'], $key)->assertConflict();
        $this->submit()->assertConflict();
        $this->assertSame('Provisional Fixture', Customer::sole()->name);
        $this->assertNull($event->fresh()->address);
        $this->assertTrue($oldStart->equalTo($event->fresh()->starts_at));
        $this->assertDatabaseCount('financial_revisions', 1); $this->assertDatabaseCount('ledger_entries', 1);
        $row = ClientDetailsSubmission::sole();
        try { $row->update(['revision' => 2]); $this->fail('Submission was mutable.'); } catch (\LogicException $e) { $this->assertNotEmpty($e->getMessage()); }
        $this->getJson('/portal/api/event')->assertJsonMissingPath('details.submission.ip')->assertJsonMissingPath('details.submission.base_customer_snapshot');
    }

    public function test_scope_revocation_and_admin_impersonation_block_details(): void
    {
        $event = $this->client(); $grant = PortalGrant::sole();
        $this->actingAs($this->owner); $this->submit()->assertForbidden(); auth()->logout();
        $grant->update(['scopes' => ['payments.read']]); $this->submit()->assertForbidden();
        $grant->update(['scopes' => ['details.submit'], 'revoked_at' => now()]); $this->submit()->assertForbidden();
        $this->assertDatabaseCount('client_details_submissions', 0);
    }

    public function test_stale_event_and_unapproved_sms_opt_in_are_rejected(): void
    {
        $event = $this->client();
        $this->submit(['sms_consent' => true, 'sms_consent_version' => 'unapproved-fixture'])->assertUnprocessable()->assertJsonValidationErrors(['sms_consent']);
        $event->forceFill(['version' => 2])->save();
        $this->submit(['expected_event_version' => 1])->assertConflict();
        $this->assertDatabaseCount('client_details_submissions', 0);
    }

    public function test_owner_approval_preserves_money_and_time_creates_new_agreement_and_is_idempotent(): void
    {
        $event = $this->client(); $this->submit()->assertCreated(); $old = FinancialRevision::sole(); $start = $event->starts_at;
        $key = (string) Str::uuid(); $this->approve(key: $key)->assertOk();
        $review = ClientDetailsReview::sole();
        $input = ['expected_event_version' => 1, 'expected_revision' => 1, 'travel_fee_confirmed' => true, 'review_note' => 'Reviewed fictitious venue and the quoted travel fee.'];
        $url = '/admin/api/events/'.$event->id.'/client-details/'.$review->submission_id.'/approve';
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $input)->assertOk();
        $this->assertSame('Fixture Client', Customer::sole()->name);
        $this->assertSame('456 Fictitious Venue', $event->fresh()->address);
        $this->assertTrue($start->equalTo($event->fresh()->starts_at));
        $this->assertSame(12500, $old->fresh()->total_cents);
        $this->assertSame(12500, FinancialRevision::latest('revision')->first()->total_cents);
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('client_details_reviews', 1);
        $this->getJson('/admin/api/quick-bookings?state=ready_to_issue')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.finance.received_cents', 6200);
    }

    public function test_requested_time_never_moves_event_and_requires_explicit_owner_resolution(): void
    {
        $event = $this->client(); $start = $event->starts_at;
        $this->submit(['start_time_confirmed' => false, 'requested_start_at' => $start->addHours(2)->toIso8601String()])->assertCreated();
        $this->approve()->assertUnprocessable()->assertJsonValidationErrors(['requested_start_resolution']);
        $this->approve(['requested_start_resolution' => 'keep_original'])->assertOk();
        $this->assertTrue($start->equalTo($event->fresh()->starts_at));
    }

    public function test_changed_contact_or_recipe_rolls_back_owner_approval_without_touching_payment(): void
    {
        $event = $this->client(); $this->submit()->assertCreated();
        $customer = Customer::sole(); $old = $customer->name; $customer->update(['name' => 'Changed in another editor']);
        $this->approve()->assertConflict(); $customer->update(['name' => $old]);
        ServiceVariant::sole()->update(['duration_minutes' => 90]);
        $this->approve()->assertConflict();
        $this->assertSame($old, Customer::sole()->name); $this->assertNull($event->fresh()->address);
        $this->assertSame(1, $event->fresh()->version);
        $this->assertDatabaseCount('financial_revisions', 1); $this->assertDatabaseCount('client_details_reviews', 0); $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_new_submission_blocks_old_signature_then_approval_supersedes_unsigned_contract(): void
    {
        $event = $this->client(); $this->submit()->assertCreated(); $this->approve()->assertOk(); $contract = $this->issue();
        auth()->logout();
        $this->get('/portal/api/contracts/'.$contract->id.'/pdf')->assertOk();
        $this->submit(['expected_revision' => 1, 'notes' => 'Updated client details'])->assertCreated();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/portal/api/contracts/'.$contract->id.'/sign', [
            'expected_version' => $contract->version, 'presented_pdf_sha256' => $contract->presented_pdf_sha256, 'signer_name' => 'Fixture Client', 'consent' => true, 'intent_to_sign' => true, 'consent_version' => 'esign-consent-v1',
        ])->assertConflict();
        $this->approve()->assertOk();
        $this->assertSame('superseded', $contract->fresh()->state); $this->assertDatabaseCount('signature_evidence', 0); $this->assertDatabaseCount('ledger_entries', 1);
    }
    public function test_client_edit_racing_owner_approval_never_approves_a_stale_submission(): void
    {
        $event = $this->client(); $this->submit()->assertCreated();
        $request = \Illuminate\Http\Request::create('/portal/api/details', 'POST');
        $request->setLaravelSession($this->app['session.store']); $request->setUserResolver(fn () => null);
        $context = \App\Services\Portal\PortalContext::fromRequest($request);
        $input = $this->details(['expected_revision' => 1, 'notes' => 'Concurrent newer details']);
        $owner = $this->owner; $eventId = $event->id; $submissionId = ClientDetailsSubmission::sole()->id;
        $workers = [
            function () use ($context, $input) {
                try { app(\App\Services\Portal\SubmitClientDetails::class)->execute($context, $input, (string) Str::uuid()); return 'submitted'; }
                catch (\App\Exceptions\RecordWriteRejected $e) { return $e->status === 409 ? 'conflict' : 'unexpected'; }
            },
            function () use ($owner, $eventId, $submissionId) {
                try { app(ApproveClientDetails::class)->execute($owner, $eventId, $submissionId, ['expected_event_version' => 1, 'expected_revision' => 1, 'travel_fee_confirmed' => true, 'review_note' => 'Reviewed fixture venue and quote.'], (string) Str::uuid()); return 'approved'; }
                catch (\App\Exceptions\RecordWriteRejected $e) { return $e->status === 409 ? 'conflict' : 'unexpected'; }
            },
        ];
        $results = ConcurrentWorkers::run($workers);
        $this->assertTrue(in_array($results, [['submitted','conflict'],['conflict','approved']], true), 'Only the current version can win the row lock.');
        $this->assertDatabaseCount('ledger_entries', 1);
        if ($results[1] === 'approved') $this->assertSame(1, ClientDetailsSubmission::count());
        else $this->assertDatabaseCount('client_details_reviews', 0);
    }

    public function test_approved_details_allow_signature_but_signed_contract_refuses_new_details(): void
    {
        $event = $this->client(); $this->submit()->assertCreated(); $this->approve()->assertOk(); $contract = $this->issue();
        auth()->logout();
        $this->get('/portal/api/contracts/'.$contract->id.'/pdf')->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/portal/api/contracts/'.$contract->id.'/sign', [
            'expected_version' => $contract->version, 'presented_pdf_sha256' => $contract->presented_pdf_sha256, 'signer_name' => 'Fixture Client', 'consent' => true, 'intent_to_sign' => true, 'consent_version' => 'esign-consent-v1',
        ])->assertOk();
        $this->submit(['expected_revision' => 1])->assertConflict();
        $this->assertDatabaseCount('signature_evidence', 1);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame('draft', $event->fresh()->status);
        $this->actingAs($this->owner);
        $this->getJson('/admin/api/quick-bookings?state=signed')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_approved_sms_wording_is_pinned_and_request_provenance_is_server_derived(): void
    {
        $this->client();
        config(['waoo.sms_consent_versions' => ['fixture-consent-v1' => 'Fictitious approved SMS copy for this test only.']]);
        $this->withHeader('User-Agent', 'Fixture request device')->submit(['sms_consent' => true, 'sms_consent_version' => 'fixture-consent-v1', 'ip' => '203.0.113.99', 'user_agent' => 'Spoofed device'])->assertCreated();
        $row = ClientDetailsSubmission::sole();
        $this->assertSame('Fixture request device', $row->user_agent);
        $this->assertNotSame('203.0.113.99', $row->ip);
        $this->assertSame(hash('sha256', 'Fictitious approved SMS copy for this test only.'), $row->data['sms_consent_record']['sha256']);
        $this->assertNull(DB::table('audit_entries')->where('action', 'portal.details_submitted')->value('actor_id'));
        Queue::assertNothingPushed();
    }

    public function test_core_audit_failure_rolls_back_all_provisional_writes(): void
    {
        $fail = true;
        DB::connection()->beforeExecuting(function ($query, $bindings) use (&$fail) {
            if ($fail && str_contains($query, 'audit_entries') && in_array('quick_booking.created', $bindings, true)) throw new \RuntimeException('Fixture audit failure');
        });
        try { app(QuickBook::class)->execute($this->owner, $this->input, (string) Str::uuid()); $this->fail('Audit failure hidden.'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture audit failure', $e->getMessage()); }
        finally { $fail = false; }
        foreach (['customers','events','financial_revisions','ledger_entries','quick_bookings','portal_grants','audit_entries','mutation_receipts'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public function test_details_write_retains_real_csrf_protection_in_staging(): void
    {
        $this->client();
        $data = $this->details(); $grantId = PortalGrant::sole()->id;
        $this->app->instance('env', 'staging'); config(['session.secure' => true]);
        $this->withSession(['_token' => 'fixture-csrf', 'portal_grant_id' => $grantId])->withHeader('X-CSRF-TOKEN', 'incorrect')
            ->postJson('https://localhost/portal/api/details', $data)->assertStatus(419)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('client_details_submissions', 0);
        $this->withSession(['_token' => 'fixture-csrf', 'portal_grant_id' => $grantId])->withHeader('X-CSRF-TOKEN', 'fixture-csrf')
            ->postJson('https://localhost/portal/api/details', $data)->assertCreated();
    }

    private function reservedClient(): Event
    {
        $this->input['address'] = '456 Fictitious Venue';
        $response = $this->create()->assertCreated();
        $event = Event::sole();
        $performer = \App\Models\Resource::create(['code' => 'QB-PERFORMER', 'name' => 'Fictitious performer', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer']]);
        app(\App\Services\Finance\ConfirmPaidCapacity::class)->execute($this->owner, $event->id, ['expected_version' => 1, 'financial_revision_id' => FinancialRevision::sole()->id, 'resource_ids' => [$performer->id],
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Fixture origin and return reviewed.', 'deposit_exception_reason' => 'Fixture owner accepts the fifty cent shortfall.', 'transfers' => []]]);
        parse_str(parse_url($response->json('portal_url'), PHP_URL_FRAGMENT), $fragment);
        auth()->logout();
        $this->postJson('/portal/api/session', ['token' => $fragment['token']])->assertOk();
        return $event->fresh();
    }

    public function test_reserved_party_can_complete_identity_without_rewriting_resources_or_agreement(): void
    {
        $event = $this->reservedClient();
        $reservations = ResourceReservation::where('event_id', $event->id)->get()->toArray();
        $evidence = DB::table('booking_evidence')->first();
        $this->submit()->assertCreated();
        $this->approve()->assertOk();
        $this->assertSame('capacity_reserved', $event->fresh()->status);
        $this->assertSame($event->version, $event->fresh()->version);
        $this->assertSame($reservations, ResourceReservation::where('event_id', $event->id)->get()->toArray());
        $this->assertEquals($evidence, DB::table('booking_evidence')->first());
        $this->assertDatabaseCount('financial_revisions', 1); $this->assertDatabaseCount('ledger_entries', 1);
        $contract = $this->issue();
        $this->assertSame(FinancialRevision::sole()->id, $contract->financial_revision_id);
    }

    public function test_reserved_venue_change_is_refused_with_commitment_and_money_intact(): void
    {
        $event = $this->reservedClient();
        $this->submit(['event_address' => '999 Different Fictitious Venue'])->assertCreated();
        $this->approve()->assertConflict();
        $this->assertSame('Provisional Fixture', Customer::sole()->name);
        $this->assertSame('456 Fictitious Venue', $event->fresh()->address);
        $this->assertDatabaseCount('client_details_reviews', 0);
        $this->assertDatabaseCount('resource_reservations', 1); $this->assertDatabaseCount('ledger_entries', 1);
    }

    private function draftInput(Event $event, array $over = []): array
    {
        return [...$event->only(['customer_id','title','event_type','timezone','address','postal_code','setup_minutes','breakdown_minutes','travel_fee_cents']),
            'starts_at' => $event->starts_at->toIso8601String(), 'ends_at' => $event->ends_at->toIso8601String(), 'expected_version' => $event->version,
            'services' => $event->services()->get()->map(fn ($service) => ['price_version_id' => $service->price_version_id])->all(), ...$over];
    }

    public function test_owner_can_supply_missing_operational_minutes_during_details_approval(): void
    {
        unset($this->input['setup_minutes'], $this->input['breakdown_minutes']);
        $event = $this->client(); $this->submit()->assertCreated();
        $this->approve()->assertUnprocessable()->assertJsonValidationErrors(['setup_minutes']);
        $this->assertDatabaseCount('client_details_reviews', 0);
        $this->approve(['setup_minutes' => 30, 'breakdown_minutes' => 20])->assertOk();
        $this->assertSame(30, $event->fresh()->setup_minutes);
        $this->assertSame(20, $event->fresh()->breakdown_minutes);
        $this->assertDatabaseCount('client_details_submissions', 1);
    }

    public function test_changed_event_cannot_sign_a_previous_presented_pdf(): void
    {
        $this->client(); $this->submit()->assertCreated(); $this->approve()->assertOk(); $contract = $this->issue();
        $event = Event::sole();
        app(\App\Services\Records\SaveEventDraft::class)->execute($this->owner, $this->draftInput($event, ['address' => 'Another Fictitious Venue']), $event->id);
        auth()->logout();
        $this->get('/portal/api/contracts/'.$contract->id.'/pdf')->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/portal/api/contracts/'.$contract->id.'/sign', [
            'expected_version' => $contract->version, 'presented_pdf_sha256' => $contract->presented_pdf_sha256, 'signer_name' => 'Fixture Client', 'consent' => true, 'intent_to_sign' => true, 'consent_version' => 'esign-consent-v1',
        ])->assertConflict();
        $this->assertDatabaseCount('signature_evidence', 0);
    }

    public function test_signed_party_refuses_generic_draft_and_financial_revision_writes(): void
    {
        $event = $this->client(); $this->submit()->assertCreated(); $this->approve()->assertOk(); $contract = $this->issue();
        auth()->logout(); $this->get('/portal/api/contracts/'.$contract->id.'/pdf')->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/portal/api/contracts/'.$contract->id.'/sign', [
            'expected_version' => $contract->version, 'presented_pdf_sha256' => $contract->presented_pdf_sha256, 'signer_name' => 'Fixture Client', 'consent' => true, 'intent_to_sign' => true, 'consent_version' => 'esign-consent-v1',
        ])->assertOk();
        $this->actingAs($this->owner); $current = $event->fresh();
        $this->putJson('/admin/api/events/'.$event->id, $this->draftInput($current, ['address' => 'Forbidden Fictitious Venue']))->assertConflict();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/admin/api/events/'.$event->id.'/financial-revisions', ['expected_version' => $current->version])->assertConflict();
        $this->assertFalse(\App\Filament\Resources\Events\EventResource::canEdit($current));
        $this->get('/admin/eventos/'.$event->id.'/editar')->assertForbidden();
        $this->assertSame('456 Fictitious Venue', $event->fresh()->address);
        $this->assertDatabaseCount('financial_revisions', 2); $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_owner_html_and_real_livewire_responses_do_not_cache_personal_link_results(): void
    {
        $this->owner->update(['password' => 'FictitiousPassword123!']); auth()->logout();
        $this->postJson('/login', ['email' => $this->owner->email, 'password' => 'FictitiousPassword123!'])->assertOk()->assertJsonPath('two_factor', true);
        $this->postJson('/two-factor-challenge', ['code' => (new \PragmaRX\Google2FA\Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP')])->assertSuccessful();
        $html = $this->get('/admin/reserva-rapida')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = null; $names = [];
        foreach ($matches[1] as $encoded) {
            $candidate = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $name = json_decode($candidate, true, flags: JSON_THROW_ON_ERROR)['memo']['name']; $names[] = $name;
            if (str_contains(str_replace('-', '', strtolower($name)), 'quickbooking')) $snapshot = $candidate;
        }
        $this->assertNotNull($snapshot, 'Actual component names: '.implode(', ', $names));
        $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => []]]]]];
        $this->app['auth']->forgetGuards();
        Livewire::flushState(); // PHPUnit reuses the app; model the next PHP-FPM request's fresh middleware cycle.
        $this->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->owner->forceFill(['active' => false])->save(); $this->app['auth']->forgetGuards();
        $this->assertDatabaseHas('users', ['id' => $this->owner->id, 'active' => false]);
        Livewire::flushState();
        $this->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('quick_bookings', 0);
    }

    public function test_timeout_retry_uses_original_ui_expiry_and_replay_after_expiration_stays_idempotent(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-15 12:00:00', 'America/Chicago'));
        try {
            $page = Livewire::test(QuickBookingPage::class);
            $state = ['price_version_ids' => [PriceVersion::sole()->id], 'event_type' => 'birthday', 'title' => 'Fixture timeout party', 'date' => '2026-12-19', 'start_time' => '15:00', 'end_time' => '16:00',
                'postal_code' => '00001', 'travel_fee' => '25', 'customer_mode' => 'new', 'name' => 'Fixture Contact', 'locale' => 'es',
                'deposit' => ['amount' => '62', 'reference' => 'QB-TIMEOUT-FIXTURE', 'date' => '2026-10-15', 'time' => '00:05', 'verified' => true], 'expires_days' => 30];
            $input = $page->instance()->toServiceInput($state); $key = $page->instance()->key;
            app(QuickBook::class)->execute($this->owner, $input, $key); // Simulate a lost save response; the browser retains its original snapshot/key.
            $this->travel(5)->seconds();
            $retry = $page->instance()->toServiceInput($state);
            $result = app(QuickBook::class)->execute($this->owner, $retry, $key);
            $this->assertNull($result->portal_url); $this->assertTrue($result->portal_link_already_shown);
            $this->travel(31)->days();
            $expired = app(QuickBook::class)->execute($this->owner, $input, $key);
            $this->assertNull($expired->portal_url); $this->assertTrue($expired->link_issue_pending);
            $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('events', 1);
        } finally { $this->travelBack(); }
    }

    public function test_route_address_approval_records_manual_reconfirmation_without_reusing_old_calculation(): void
    {
        config(['waoo.sandbox_routes' => true]); \Illuminate\Support\Facades\Http::fake();
        $address = '123 Fictional Venue, TX 77433';
        $quote = app(\App\Services\Routing\TravelFeeQuote::class)->forAddress($this->owner, $address, '77433');
        $this->input = [...$this->input, 'address' => $address, 'postal_code' => '77433', 'travel_fee_cents' => 4000, 'travel_fee_quote_id' => $quote['quote_id']];
        $event = $this->client();
        $this->submit(['event_address' => '456 Different Fictional Venue, TX 77433', 'event_postal_code' => '77433'])->assertCreated();
        $this->approve()->assertOk(); $evidence = $event->fresh()->travel_fee_evidence;
        $this->assertSame('manual', $evidence['mode']); $this->assertSame($quote['quote_id'], $evidence['previous_quote_id']);
        $this->assertSame(4000, $evidence['accepted_fee_cents']);
        $this->assertSame(1, LedgerEntry::count()); \Illuminate\Support\Facades\Http::assertNothingSent();
    }

}
