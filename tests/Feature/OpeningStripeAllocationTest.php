<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, LedgerEntry, OpeningReceiptAllocation, OpeningStripeEvidence, PaymentIntent, WebhookReceipt};
use App\Services\Finance\{AllocateOpeningReceipt, EffectiveOpeningAgreement, FinanceSummary, ReadOpeningReceiptCandidates, StripeGateway, StripeReceiptProcessor};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Mail, Schema};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\{ConcurrentWorkers, EffectiveOpeningScenario, FakeStripeGateway};
use Tests\TestCase;

class OpeningStripeAllocationTest extends TestCase
{
    use DatabaseMigrations, EffectiveOpeningScenario;
    private FakeStripeGateway $stripe;
    private int $eventId;
    protected function setUp(): void
    {
        parent::setUp(); $this->futureScenario();
        config(['waoo.sandbox_cutover' => true, 'waoo.sandbox_cutover_bridge' => true,
            'waoo.stripe_test_account' => 'acct_fixture', 'waoo.stripe_test_webhook_secret' => 'whsec_fixture']);
        Http::preventStrayRequests(); Mail::fake();
        $this->stripe = new FakeStripeGateway(); $this->app->instance(StripeGateway::class, $this->stripe);
        $link = $this->openingMember(change: ['client_first_name' => 'María', 'client_last_name' => 'González',
            'client_email' => 'maria.gonzalez@example.test', 'project_name' => 'Cumpleaños de Daniela']);
        $this->eventId = $link->event_id; $this->seedFutureActivation($this->prepareOpeningSet());
    }
    protected function tearDown(): void
    {
        if ($this->app) {
            $this->app->detectEnvironment(fn () => 'testing');
            foreach (['opening_stripe_evidence', 'opening_receipt_allocations', 'cutover_active_openings', 'cutover_financial_bindings',
                'cutover_transfers', 'cutover_opening_agreements', 'cutover_opening_sets', 'cutover_reconciliations', 'honeybook_cutover_reviews',
                'cutover_original_documents', 'payment_exceptions', 'ledger_entries', 'payment_intents', 'financial_revisions'] as $t)
                if (Schema::hasTable($t)) DB::table($t)->delete();
            if (Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        }
        parent::tearDown();
    }
    /** Future checkout fixture only: imported checkout remains disabled in the production API. */
    private function paid(int $principal = 2000, int $fee = 0, bool $process = true, ?int $received = null, string $purpose = 'balance'): PaymentIntent
    {
        $c = EffectiveOpeningAgreement::find(Event::findOrFail($this->eventId));
        $intent = PaymentIntent::create(['id' => (string)Str::uuid(), 'event_id' => $this->eventId, 'financial_revision_id' => $c['finance']->id,
            'environment' => 'test', 'purpose' => $purpose, 'currency' => 'USD', 'principal_cents' => $principal, 'customer_fee_cents' => $fee,
            'payable_cents' => $principal + $fee, 'status' => 'open', 'expires_at' => now()->addHour()]);
        $metadata = ['local_intent_id' => $intent->id, 'event_id' => (string)$this->eventId, 'financial_revision_id' => (string)$c['finance']->id];
        $session = $this->stripe->createCheckout(['client_reference_id' => $intent->id, 'metadata' => $metadata,
            'line_items' => [['price_data' => ['unit_amount' => $principal + $fee]]]], 'fixture-'.$intent->id);
        $intent->forceFill(['stripe_checkout_session_id' => $session['id']])->save(); $this->stripe->paid($session['id']);
        $this->stripe->sessions[$session['id']]['payment_intent']['latest_charge']['created'] = $received ?? now()->timestamp;
        $this->deliver($intent, 'evt_s1_'.$intent->id, $process);
        return $intent->fresh();
    }
    private function deliver(PaymentIntent $intent, string $webhookId, bool $process = true): void
    {
        $session = $this->stripe->sessions[$intent->stripe_checkout_session_id];
        $raw = json_encode(['id' => $webhookId, 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => false,
            'account' => 'acct_fixture', 'created' => time(), 'data' => ['object' => ['id' => $session['id'], 'object' => 'checkout.session', 'metadata' => $session['metadata']]]], JSON_THROW_ON_ERROR);
        $time = time(); $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, 'whsec_fixture');
        $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $raw)->assertOk();
        if ($process) app(StripeReceiptProcessor::class)->execute($webhookId);
    }
    private function summary(): array { return app(FinanceSummary::class)->forEvent(Event::findOrFail($this->eventId)); }
    private function candidates(): array { return app(ReadOpeningReceiptCandidates::class)->execute($this->owner, $this->eventId); }
    private function input(int $ledgerId): array
    {
        $v = $this->candidates(); $r = collect($v['rows'])->firstWhere('ledger_entry_id', $ledgerId);
        return ['opening_id' => $v['opening_id'], 'expected_collection_sha256' => $v['expected_collection_sha256'],
            'expected_receipt_sha256' => $r['receipt_sha256'], 'post_cutover_received' => true];
    }
    private function allocate(LedgerEntry $entry, ?array $input = null, ?string $key = null): array
    { return app(AllocateOpeningReceipt::class)->execute($this->owner, $this->eventId, $entry->id, $input ?? $this->input($entry->id), $key ?? (string)Str::uuid()); }

    public function test_signed_provider_receipt_is_retained_then_explicitly_allocated_once_without_source_replay(): void
    {
        $intent = $this->paid(); $entry = LedgerEntry::sole(); $v = $this->candidates();
        $this->assertTrue($v['rows'][0]['eligible']); $this->assertSame(2000, $v['rows'][0]['principal_cents']);
        $this->assertNull($this->summary()['balance_cents']); $this->assertDatabaseCount('opening_stripe_evidence', 1);
        $input = $this->input($entry->id); $key = (string)Str::uuid(); $first = $this->allocate($entry, $input, $key);
        $this->assertSame($first, $this->allocate($entry, $input, $key));
        $this->assertSame($first, $this->allocate($entry, $input));
        $s = $this->summary(); $this->assertSame([6000, 2000, 8000, 4000, 0], array_map(fn ($k) => $s[$k],
            ['source_net_received_cents', 'crm_received_cents', 'net_received_cents', 'balance_cents', 'crm_customer_fee_cents']));
        $this->assertFalse($s['collection_blocked']); $this->assertFalse($s['funds_verified']);
        $this->deliver($intent, 'evt_s1_second'); $this->assertDatabaseCount('opening_stripe_evidence', 1);
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('opening_receipt_allocations', 1);
        $this->assertSame($first, $this->allocate($entry, $input, $key));
        $this->assertDatabaseCount('mail_occurrences', 0); $this->assertDatabaseCount('outbound_messages', 0);
        Http::assertNothingSent(); Mail::assertNothingSent();
        $serialized = json_encode($v); $this->assertStringNotContainsString('acct_fixture', $serialized);
        $this->assertStringNotContainsString('pi_fixture', $serialized);
        $this->assertStringNotContainsString('maria.gonzalez', DB::table('opening_stripe_evidence')->get()->toJson());
    }
    public function test_explicit_fee_is_not_principal_balance_credit_and_no_new_surcharge_is_configured(): void
    {
        $this->paid(2000, 60); $entry = LedgerEntry::sole(); $result = $this->allocate($entry); $s = $this->summary();
        $this->assertSame(2060, $result['amount_cents']); $this->assertSame(2000, $result['principal_cents']); $this->assertSame(60, $result['customer_fee_cents']);
        $this->assertSame(2060, $entry->amount_cents); $this->assertSame(2000, $s['crm_received_cents']);
        $this->assertSame(60, $s['crm_customer_fee_cents']); $this->assertSame(4000, $s['balance_cents']);
        $this->assertSame(8000, $s['net_received_cents']); $this->assertSame(2, OpeningReceiptAllocation::sole()->payload['schema_version']);
    }
    public static function evidenceMutations(): array
    {
        return ['envelope' => ['envelope'], 'ledger amount' => ['ledger'], 'intent fee' => ['intent'], 'webhook raw hash' => ['webhook'],
            'account config' => ['account'], 'unsupported version' => ['version']];
    }
    #[DataProvider('evidenceMutations')]
    public function test_changed_evidence_never_becomes_balance_credit_and_keeps_real_money(string $what): void
    {
        $this->paid(); $entry = LedgerEntry::sole(); $this->allocate($entry);
        match ($what) {
            'envelope' => DB::table('opening_stripe_evidence')->update(['payload' => 'damaged encrypted evidence']),
            'ledger' => DB::table('ledger_entries')->where('id', $entry->id)->increment('amount_cents'),
            'intent' => DB::table('payment_intents')->increment('customer_fee_cents'),
            'webhook' => DB::table('webhook_receipts')->update(['raw_sha256' => str_repeat('0', 64)]),
            'account' => config(['waoo.stripe_test_account' => 'acct_other']),
            'version' => $this->changeEvidenceVersion(),
        };
        $this->assertNull($this->summary()['balance_cents']); $this->assertTrue($this->summary()['collection_blocked']);
        $r = $this->candidates()['rows'][0]; $this->assertFalse($r['eligible']); $this->assertNull($r['principal_cents']);
        $this->assertContains('stripe_evidence_invalid', $r['issue_codes']); $this->assertDatabaseCount('ledger_entries', 1);
        $this->expectException(RecordWriteRejected::class); $this->allocate($entry->fresh());
    }
    private function changeEvidenceVersion(): int
    {
        $row = OpeningStripeEvidence::sole(); $p = $row->payload; $p['schema_version'] = 99;
        return DB::table('opening_stripe_evidence')->where('id', $row->id)->update([
            'payload' => (new OpeningStripeEvidence)->forceFill(['payload' => $p])->getAttributes()['payload'],
            'payload_sha256' => \App\Services\Finance\MutationKey::hash($p)]);
    }
    public function test_capture_survives_invalid_authority_but_never_silently_allocates(): void
    {
        $i = $this->paid(process: false); DB::table('cutover_transfers')->update(['payload' => 'damaged authority']);
        app(StripeReceiptProcessor::class)->execute('evt_s1_'.$i->id);
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('opening_stripe_evidence', 1);
        $this->assertDatabaseCount('opening_receipt_allocations', 0); $this->assertSame('paid', $i->fresh()->status);
        $this->assertSame('processed', WebhookReceipt::sole()->state); $this->assertNull($this->summary()['balance_cents']);
    }
    public function test_legacy_receipt_without_capture_is_not_backfilled_by_a_duplicate_webhook(): void
    {
        $i = $this->paid(); DB::table('opening_stripe_evidence')->delete(); $this->deliver($i, 'evt_legacy_duplicate');
        $r = $this->candidates()['rows'][0]; $this->assertFalse($r['eligible']); $this->assertContains('stripe_evidence_required', $r['issue_codes']);
        $this->assertDatabaseCount('opening_stripe_evidence', 0); $this->assertDatabaseCount('ledger_entries', 1);
        $this->expectException(RecordWriteRejected::class); $this->allocate(LedgerEntry::sole());
    }
    public function test_pre_cutover_provider_receipt_remains_real_but_cannot_be_allocated(): void
    {
        $this->paid(received: now()->subSecond()->timestamp); $r = $this->candidates()['rows'][0];
        $this->assertFalse($r['eligible']); $this->assertContains('receipt_time_outside_transfer', $r['issue_codes']);
        $this->assertDatabaseCount('opening_stripe_evidence', 1); $this->assertNull($this->summary()['balance_cents']);
    }
    public function test_new_columns_do_not_invalidate_proof_and_mutable_processing_state_is_not_hashed(): void
    {
        $i = $this->paid(); $entry = LedgerEntry::sole(); $this->allocate($entry);
        try {
            foreach (['payment_intents', 'webhook_receipts', 'opening_stripe_evidence'] as $t)
                Schema::table($t, fn ($b) => $b->string('future_display_column')->default('new'));
            $i->forceFill(['collection_slot' => null, 'expires_at' => now()->addDays(2)])->save();
            WebhookReceipt::sole()->increment('attempts');
            $this->assertSame(4000, $this->summary()['balance_cents']); $this->assertTrue($this->candidates()['rows'][0]['eligible']);
        } finally { foreach (['payment_intents', 'webhook_receipts', 'opening_stripe_evidence'] as $t) Schema::table($t, fn ($b) => $b->dropColumn('future_display_column')); }
    }
    public function test_capture_failure_rolls_back_whole_receipt_then_same_webhook_recovers(): void
    {
        $i = $this->paid(process: false); $fail = true;
        OpeningStripeEvidence::creating(function () use (&$fail) { if ($fail) throw new \RuntimeException('Synthetic evidence storage failure'); });
        try {
            try { app(StripeReceiptProcessor::class)->execute('evt_s1_'.$i->id); $this->fail('Expected capture failure'); }
            catch (\RuntimeException $e) { $this->assertSame('Synthetic evidence storage failure', $e->getMessage()); }
            $this->assertDatabaseCount('ledger_entries', 0); $this->assertDatabaseCount('opening_stripe_evidence', 0);
            $this->assertSame('queued', WebhookReceipt::sole()->state); $fail = false;
            app(StripeReceiptProcessor::class)->execute('evt_s1_'.$i->id); $this->assertDatabaseCount('ledger_entries', 1);
            $this->assertDatabaseCount('opening_stripe_evidence', 1); $this->assertTrue($this->candidates()['rows'][0]['eligible']);
        } finally { $fail = false; }
    }
    public function test_independent_concurrent_owner_assignments_credit_the_same_provider_receipt_once(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires MariaDB and pcntl.');
        $this->paid(); $entry = LedgerEntry::sole(); $input = $this->input($entry->id);
        $results = ConcurrentWorkers::run([fn () => $this->allocate($entry, $input), fn () => $this->allocate($entry, $input)]);
        $this->assertSame($results[0], $results[1]); $this->assertIsArray($results[0]);
        $this->assertDatabaseCount('opening_receipt_allocations', 1); $this->assertSame(4000, $this->summary()['balance_cents']);
    }
    public function test_existing_installment_purpose_can_be_explicitly_allocated_without_a_second_deposit(): void
    {
        $this->paid(purpose: 'installment'); $this->allocate(LedgerEntry::sole());
        $s = $this->summary(); $this->assertSame(4000, $s['balance_cents']); $this->assertSame(0, $s['deposit_shortfall_cents']);
    }
    public function test_encrypted_evidence_from_another_receipt_cannot_be_transplanted(): void
    {
        $this->paid(); $this->paid(1000); $rows = OpeningStripeEvidence::orderBy('id')->get();
        $raw = $rows[1]->getRawOriginal();
        DB::table('opening_stripe_evidence')->where('id', $rows[0]->id)->update(['payload' => $raw['payload'], 'payload_sha256' => $raw['payload_sha256']]);
        $v = $this->candidates(); $this->assertFalse($v['rows'][0]['eligible']); $this->assertTrue($v['rows'][1]['eligible']);
        $this->assertNull($this->summary()['balance_cents']); $this->assertDatabaseCount('ledger_entries', 2);
    }
    public function test_invalid_provider_amount_never_creates_evidence_or_money(): void
    {
        $i = $this->paid(process: false);
        $this->stripe->sessions[$i->stripe_checkout_session_id]['payment_intent']['amount_received']++;
        app(StripeReceiptProcessor::class)->execute('evt_s1_'.$i->id);
        $this->assertDatabaseCount('ledger_entries', 0); $this->assertDatabaseCount('opening_stripe_evidence', 0);
        $this->assertSame('reconciliation_required', $i->fresh()->status);
    }
    public function test_concurrent_webhook_processing_creates_one_immutable_capture(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires MariaDB and pcntl.');
        $i = $this->paid(process: false); $work = function () use ($i) {
            app(StripeReceiptProcessor::class)->execute('evt_s1_'.$i->id); return 'processed';
        };
        $this->assertSame(['processed', 'processed'], ConcurrentWorkers::run([$work, $work]));
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('opening_stripe_evidence', 1);
        $this->assertTrue($this->candidates()['rows'][0]['eligible']);
        try { OpeningStripeEvidence::sole()->update(['payload_sha256' => str_repeat('0', 64)]); $this->fail('Mutable evidence'); }
        catch (\LogicException $e) { $this->assertStringContainsString('immutable', $e->getMessage()); }
    }
    public function test_refund_without_a_verified_attribution_keeps_combined_balance_unknown(): void
    {
        $this->paid(2000, 60); $entry = LedgerEntry::sole(); $this->allocate($entry);
        LedgerEntry::create(['event_id' => $this->eventId, 'financial_revision_id' => $entry->financial_revision_id,
            'environment' => 'test', 'currency' => 'USD', 'kind' => 'refund', 'method' => 'stripe', 'amount_cents' => 100,
            'received_at' => now(), 'external_reference' => 're_unverified_daniela', 'external_identity_key' => 'unverified:test:refund:daniela', 'source_type' => 'unverified_fixture']);
        $s = $this->summary(); $this->assertNull($s['balance_cents']); $this->assertSame(2000, $s['crm_received_cents']);
        $this->assertSame(60, $s['crm_customer_fee_cents']); $this->assertTrue($s['collection_blocked']);
        $r = $this->candidates()['rows'][1]; $this->assertFalse($r['eligible']); $this->assertContains('refund_allocation_pending', $r['issue_codes']);
        $this->assertDatabaseCount('opening_receipt_allocations', 1);
    }
    public function test_changed_displayed_receipt_or_request_body_cannot_be_retried_as_a_new_decision(): void
    {
        $this->paid(); $entry = LedgerEntry::sole(); $input = $this->input($entry->id); $key = (string)Str::uuid();
        $first = $this->allocate($entry, $input, $key); $input['expected_receipt_sha256'] = str_repeat('0', 64);
        try { $this->allocate($entry, $input, $key); $this->fail('Stale receipt accepted'); }
        catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        $this->assertSame($first, $this->allocate($entry, key: $key)); $this->assertDatabaseCount('opening_receipt_allocations', 1);
    }
    public function test_populated_migration_refuses_rollback_and_empty_schema_roundtrip_is_safe(): void
    {
        $migration = require database_path('migrations/2026_10_20_500000_create_opening_stripe_evidence.php');
        $migration->down(); $this->assertFalse(Schema::hasTable('opening_stripe_evidence')); $migration->up();
        $this->paid();
        try { $migration->down(); $this->fail('Destroyed receipt evidence'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('forward migration', $e->getMessage()); }
        $this->assertDatabaseCount('opening_stripe_evidence', 1);
    }
}
