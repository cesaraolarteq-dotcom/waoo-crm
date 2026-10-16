<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, PortalGrant, PriceVersion, ServiceVariant, User, ZelleNotice, ZelleNoticeReview};
use App\Services\Finance\{CreateFinancialRevision, FinanceSummary, RecordZellePayment, ReviewZelleNotice};
use App\Services\Portal\{IssuePortalGrant, PortalContext, RevokePortalGrant, SubmitZelleNotice};
use App\Services\Records\SaveEventDraft;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue};
use Illuminate\Support\Str;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class ZelleNoticesTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private Event $event;
    private FinancialRevision $finance;
    private array $bank;
    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]); Queue::fake();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $customer = Customer::create(['name' => 'Fixture Client', 'email' => 'fixture@example.test', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fixture package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = now()->addDays(20)->startOfHour();
        $this->event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id, 'title' => 'Fixture party', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => 5500, 'services' => [['price_version_id' => $price->id]]]);
        $this->finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        $this->bank = ['financial_revision_id' => $this->finance->id, 'amount_cents' => 27500, 'currency' => 'USD', 'reference' => 'FIXTURE-BANK-REFERENCE', 'received_at' => now()->subHour()->startOfSecond()->toIso8601String(), 'verified_received' => true];
    }
    private function client(array $scopes = ['payments.read','checkout.create']): PortalContext
    {
        $grant = app(IssuePortalGrant::class)->execute($this->owner, $this->event->id, ['customer_id' => $this->event->customer_id, 'expires_at' => now()->addDay()->toIso8601String(), 'scopes' => $scopes]);
        auth()->logout(); $this->app['auth']->forgetGuards();
        $this->postJson('/portal/api/session', ['token' => $grant->token])->assertOk();
        $request = Request::create('/portal/api/zelle-notices', 'POST', server: ['REMOTE_ADDR' => '192.0.2.25', 'HTTP_USER_AGENT' => 'Fixture browser']);
        $request->setLaravelSession($this->app['session.store']); $request->setUserResolver(fn () => null);
        return PortalContext::fromRequest($request);
    }
    private function claim(array $extra = []): array { return [...['financial_revision_id' => $this->finance->id, 'amount_cents' => 22750, 'reference' => 'client-claim-only'], ...$extra]; }
    private function submit(array $extra = [], ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/portal/api/zelle-notices', $this->claim($extra));
    }
    private function review(int $id, array $extra = [], ?string $key = null)
    {
        $this->actingAs($this->owner);
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/admin/api/events/'.$this->event->id.'/zelle-notices/'.$id.'/review', [...$this->bank, 'decision' => 'verified', 'review_note' => 'Fixture bank amount independently checked.', ...$extra]);
    }
    public function test_claim_is_not_money_and_retries_cannot_duplicate_it(): void
    {
        $this->client(); $key = (string) Str::uuid();
        $id = $this->submit(['verified_received' => true, 'customer_id' => 999, 'status' => 'verified'], $key)->assertCreated()->assertJsonPath('notice.state', 'pending')->json('notice.id');
        $this->submit([], $key)->assertCreated()->assertJsonPath('notice.id', $id);
        $this->submit(['amount_cents' => 100], $key)->assertConflict();
        $this->assertDatabaseCount('zelle_notices', 1); $this->assertDatabaseCount('ledger_entries', 0); $this->assertDatabaseCount('resource_reservations', 0);
        $this->assertSame(45500, app(FinanceSummary::class)->forEvent($this->event)['balance_cents']);
        $this->assertSame('draft', $this->event->fresh()->status);
        $this->getJson('/portal/api/zelle-notices')->assertOk()->assertJsonPath('data.0.state', 'pending')->assertJsonMissingPath('data.0.ip')->assertHeader('Cache-Control', 'no-store, private');
    }
    public function test_owner_verifies_actual_amount_once_and_portal_does_not_expose_bank_evidence(): void
    {
        $this->client(); $id = $this->submit()->assertCreated()->json('notice.id'); $key = (string) Str::uuid();
        $reviewId = $this->review($id, [], $key)->assertOk()->assertJsonPath('finance.balance_cents', 18000)->json('review.id');
        $this->review($id, [], $key)->assertOk()->assertJsonPath('review.id', $reviewId);
        $this->review($id)->assertConflict();
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('zelle_notice_reviews', 1); $this->assertDatabaseCount('resource_reservations', 0);
        $this->assertSame(22750, ZelleNotice::findOrFail($id)->amount_cents); $this->assertSame(27500, LedgerEntry::sole()->amount_cents);
        $this->getJson('/admin/api/zelle-notices')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/admin/api/zelle-notices?state=verified')->assertOk()->assertJsonPath('total', 1);
        auth()->logout(); $this->app['auth']->forgetGuards();
        $this->getJson('/portal/api/zelle-notices')->assertOk()->assertJsonPath('data.0.state', 'verified')->assertJsonPath('data.0.verified_amount_cents', 27500)
            ->assertJsonMissingPath('data.0.ledger_entry_id')->assertJsonMissingPath('data.0.review_note')->assertDontSee('FIXTURE-BANK-REFERENCE')->assertDontSee('independently checked');
    }
    public function test_rejection_is_audited_without_receipt_and_cannot_be_replaced(): void
    {
        $this->client(); $id = $this->submit()->assertCreated()->json('notice.id');
        $this->review($id, ['decision' => 'rejected', 'review_note' => 'Fixture transfer not found in bank.'])->assertOk()->assertJsonPath('review.ledger_entry_id', null)->assertJsonPath('finance.balance_cents', 45500);
        $this->review($id)->assertConflict();
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseHas('audit_entries', ['action' => 'payment.zelle_notice_reviewed', 'actor_id' => $this->owner->id]);
        $this->getJson('/admin/api/zelle-notices?state=rejected')->assertOk()->assertJsonPath('total', 1);
    }
    public function test_manually_recorded_matching_transfer_is_linked_without_duplicate_credit(): void
    {
        $this->client(); $id = $this->submit()->assertCreated()->json('notice.id');
        $entry = app(RecordZellePayment::class)->execute($this->owner, $this->event->id, $this->bank, (string) Str::uuid());
        $this->review($id, ['reference' => strtolower($this->bank['reference'])])->assertOk()->assertJsonPath('review.ledger_entry_id', $entry->id);
        $this->assertDatabaseCount('ledger_entries', 1);
        $second = app(ZelleNotice::class)->newQuery()->create([...ZelleNotice::findOrFail($id)->only(['event_id','customer_id','financial_revision_id','portal_grant_id','amount_cents','submitted_at']), 'reference' => 'another client claim']);
        $this->review($second->id, ['amount_cents' => 999])->assertConflict();
        $this->assertNull($second->review); $this->assertDatabaseCount('ledger_entries', 1);
    }
    public function test_stale_notice_preserves_claim_and_owner_must_use_current_agreement(): void
    {
        $this->client(); $key = (string) Str::uuid(); $id = $this->submit([], $key)->assertCreated()->json('notice.id');
        $latest = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        $this->submit([], $key)->assertCreated()->assertJsonPath('notice.id', $id);
        $this->submit()->assertConflict();
        $this->review($id)->assertConflict();
        $this->review($id, ['financial_revision_id' => $latest->id])->assertOk();
        $this->assertSame($this->finance->id, ZelleNotice::findOrFail($id)->financial_revision_id);
        $this->assertSame($latest->id, LedgerEntry::sole()->financial_revision_id);
    }
    public function test_revoked_scope_admin_and_customer_boundaries_are_enforced(): void
    {
        $context = $this->client(['payments.read']); $this->submit()->assertForbidden();
        $this->getJson('/portal/api/zelle-notices')->assertOk();
        $context = $this->client();
        $this->actingAs($this->owner); $this->submit()->assertForbidden(); auth()->logout(); $this->app['auth']->forgetGuards();
        app(RevokePortalGrant::class)->execute($this->owner, $context->grantId);
        try { app(SubmitZelleNotice::class)->execute($context, $this->claim(), (string) Str::uuid()); $this->fail('Revoked context accepted.'); }
        catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertDatabaseCount('zelle_notices', 0); }
        $this->client(); $this->submit()->assertCreated();
        $other = Customer::create(['name' => 'Other fixture', 'locale' => 'es']);
        DB::table('events')->where('id', $this->event->id)->update(['customer_id' => $other->id]);
        $this->getJson('/portal/api/zelle-notices')->assertNotFound(); $this->submit()->assertNotFound();
    }
    public function test_scoped_list_and_owner_route_cannot_cross_events(): void
    {
        $this->client(); $id = $this->submit()->assertCreated()->json('notice.id');
        $otherCustomer = Customer::create(['name' => 'Other fixture', 'locale' => 'es']);
        $other = $this->event->replicate(); $other->customer_id = $otherCustomer->id; $other->save();
        $grant = app(IssuePortalGrant::class)->execute($this->owner, $other->id, ['customer_id' => $otherCustomer->id, 'expires_at' => now()->addDay()->toIso8601String(), 'scopes' => ['payments.read']]);
        $this->postJson('/portal/api/session', ['token' => $grant->token])->assertOk();
        $this->getJson('/portal/api/zelle-notices?event_id='.$this->event->id)->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($this->owner)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/admin/api/events/'.$other->id.'/zelle-notices/'.$id.'/review', [...$this->bank, 'decision' => 'verified', 'review_note' => 'Fixture wrong event request'])->assertNotFound();
        $this->assertDatabaseCount('ledger_entries', 0);
    }
    public function test_audit_failure_rolls_back_receipt_review_and_mutation_keys_together(): void
    {
        $this->client(); $id = $this->submit()->assertCreated()->json('notice.id'); $count = DB::table('mutation_receipts')->count(); $fail = true;
        DB::connection()->beforeExecuting(function ($query, $bindings) use (&$fail) {
            if ($fail && str_contains($query, 'audit_entries') && in_array('payment.zelle_notice_reviewed', $bindings, true)) throw new \RuntimeException('Fixture audit failure');
        });
        try { app(ReviewZelleNotice::class)->execute($this->owner, $this->event->id, $id, [...$this->bank, 'decision' => 'verified', 'review_note' => 'Fixture verified amount'], (string) Str::uuid()); $this->fail('Audit failure ignored.'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture audit failure', $e->getMessage()); }
        finally { $fail = false; }
        $this->assertDatabaseCount('ledger_entries', 0); $this->assertDatabaseCount('zelle_notice_reviews', 0); $this->assertDatabaseCount('mutation_receipts', $count);
        $this->review($id)->assertOk();
    }
    public function test_write_requires_real_csrf_in_staging_and_sandbox_and_owner_checks(): void
    {
        $context = $this->client(); $this->app->instance('env', 'staging'); config(['session.secure' => true]);
        $this->withSession(['_token' => 'fixture-csrf', 'portal_grant_id' => $context->grantId])->withHeader('X-CSRF-TOKEN', 'wrong')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('https://localhost/portal/api/zelle-notices', $this->claim())->assertStatus(419)->assertHeader('Cache-Control', 'no-store, private');
        $this->withHeader('X-CSRF-TOKEN', 'fixture-csrf')->postJson('https://localhost/portal/api/zelle-notices', $this->claim())->assertCreated();
        $this->app->instance('env', 'testing');
        $id = ZelleNotice::sole()->id; $this->owner->forceFill(['active' => false])->save();
        $this->review($id)->assertForbidden(); $this->assertDatabaseCount('ledger_entries', 0);
        $this->owner->forceFill(['active' => true])->save(); config(['waoo.sandbox_finance' => false]);
        $this->review($id)->assertForbidden(); $this->assertDatabaseCount('ledger_entries', 0);
    }
    public function test_invalid_claims_and_unverified_owner_evidence_never_create_money(): void
    {
        $this->client();
        foreach ([['amount_cents' => 0], ['amount_cents' => 100000001], ['sent_at' => now()->addDay()->toIso8601String()], ['sent_at' => '2026-10-15 12:00:00']] as $invalid) {
            $this->submit($invalid)->assertUnprocessable();
        }
        $this->assertDatabaseCount('zelle_notices', 0);
        $id = $this->submit()->assertCreated()->json('notice.id');
        $this->review($id, ['verified_received' => false])->assertUnprocessable();
        $this->review($id, ['amount_cents' => null])->assertUnprocessable();
        $this->assertDatabaseCount('ledger_entries', 0); $this->assertDatabaseCount('zelle_notice_reviews', 0);
        $this->getJson('/admin/api/zelle-notices?per_page=5000')->assertUnprocessable();
        auth()->logout(); $this->app['auth']->forgetGuards();
        $this->getJson('/portal/api/zelle-notices?per_page=5000')->assertUnprocessable();
    }
    public function test_two_client_retries_create_one_notice(): void
    {
        $context = $this->client(); $input = $this->claim(); $key = (string) Str::uuid();
        $worker = fn () => app(SubmitZelleNotice::class)->execute($context, $input, $key)->id;
        $results = ConcurrentWorkers::run([$worker, $worker]);
        $this->assertSame($results[0], $results[1]); $this->assertIsInt($results[0]);
        $this->assertDatabaseCount('zelle_notices', 1); $this->assertDatabaseCount('ledger_entries', 0);
    }
    public function test_two_admin_decisions_cannot_resolve_notice_twice(): void
    {
        $this->client(); $id = $this->submit()->assertCreated()->json('notice.id'); $actor = $this->owner; $eventId = $this->event->id; $bank = $this->bank;
        $worker = function (string $decision) use ($id, $actor, $eventId, $bank) {
            try { app(ReviewZelleNotice::class)->execute($actor, $eventId, $id, [...$bank, 'decision' => $decision, 'review_note' => 'Fixture concurrent review'], (string) Str::uuid()); return $decision; }
            catch (\App\Exceptions\RecordWriteRejected $e) { return $e->status === 409 ? 'conflict' : 'unexpected'; }
        };
        $results = ConcurrentWorkers::run([fn () => $worker('verified'), fn () => $worker('rejected')]);
        $this->assertContains('conflict', $results); $this->assertDatabaseCount('zelle_notice_reviews', 1);
        $decision = ZelleNoticeReview::sole()->decision;
        $this->assertContains($decision, $results); $this->assertDatabaseCount('ledger_entries', $decision === 'verified' ? 1 : 0);
    }
    public function test_concurrent_manual_receipt_and_notice_verification_credit_transfer_once(): void
    {
        $this->client(); $id = $this->submit()->assertCreated()->json('notice.id'); $actor = $this->owner; $eventId = $this->event->id; $bank = $this->bank;
        $results = ConcurrentWorkers::run([
            fn () => app(RecordZellePayment::class)->execute($actor, $eventId, $bank, (string) Str::uuid())->id,
            fn () => app(ReviewZelleNotice::class)->execute($actor, $eventId, $id, [...$bank, 'decision' => 'verified', 'review_note' => 'Fixture concurrent receipt'], (string) Str::uuid())->ledger_entry_id,
        ]);
        $this->assertIsInt($results[0]); $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('zelle_notice_reviews', 1);
        $this->assertSame(18000, app(FinanceSummary::class)->forEvent($this->event)['balance_cents']);
    }
}
