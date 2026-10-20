<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{CutoverFinancialBinding, CutoverTransfer, Event, FinancialRevision, LedgerEntry, OpeningReceiptAllocation, Resource, User};
use App\Services\Finance\{AllocateOpeningReceipt, EffectiveOpeningAgreement, FinanceSummary, MutationKey, ReadOpeningReceiptCandidates, RecordZellePayment};
use App\Services\Audit;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Mail, Queue, Schema};
use Illuminate\Support\Str;
use Tests\Support\{ConcurrentWorkers, EffectiveOpeningScenario};
use Tests\TestCase;

class OpeningReceiptBridgeTest extends TestCase
{
    use DatabaseMigrations, EffectiveOpeningScenario;
    protected function setUp(): void
    {
        parent::setUp(); $this->futureScenario(); config(['waoo.sandbox_cutover' => true, 'waoo.sandbox_cutover_bridge' => true]);
        Http::preventStrayRequests(); Mail::fake();
    }
    protected function tearDown(): void
    {
        if ($this->app) {
            $this->app->detectEnvironment(fn () => 'testing');
            foreach (['opening_receipt_allocations', 'cutover_active_openings', 'cutover_financial_bindings', 'cutover_transfers',
                'cutover_opening_agreements', 'cutover_opening_sets', 'cutover_reconciliations', 'honeybook_cutover_reviews', 'cutover_original_documents'] as $t) if (Schema::hasTable($t)) DB::table($t)->delete();
            // Disposable synthetic source finance rows can have genuinely unknown deadlines.
            foreach (['payment_exceptions', 'ledger_entries', 'payment_intents', 'financial_revisions'] as $t) if (Schema::hasTable($t)) DB::table($t)->delete();
            if (Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        }
        parent::tearDown();
    }
    private function ready(): int
    {
        $link = $this->openingMember(); $this->seedFutureActivation($this->prepareOpeningSet()); return $link->event_id;
    }
    private function pay(int $id, array $change = [], ?string $key = null): LedgerEntry
    { return app(RecordZellePayment::class)->execute($this->owner, $id, $this->sourcePaymentInput($id, $change), $key ?? (string)Str::uuid()); }
    private function summary(int $id): array { return app(FinanceSummary::class)->forEvent(Event::findOrFail($id)); }
    private function candidates(int $id, array $filters = []): array { return app(ReadOpeningReceiptCandidates::class)->execute($this->owner, $id, $filters); }
    private function detachedReceipt(int $id, array $change = [], bool $audit = true): LedgerEntry
    {
        $c = EffectiveOpeningAgreement::find(Event::findOrFail($id));
        $entry = LedgerEntry::create(['event_id' => $id, 'financial_revision_id' => $c['finance']->id, 'environment' => 'test',
            'currency' => 'USD', 'kind' => 'receipt', 'method' => 'zelle', 'amount_cents' => 1000, 'received_at' => now(),
            'external_reference' => 'PRIVATE_DETACHED_BANK', 'external_identity_key' => 'zelle:test:'.hash('sha256', 'private_detached_bank'),
            'verified_by' => $this->owner->id, 'source_type' => 'owner_verified', 'note' => 'PRIVATE_DETACHED_NOTE', ...$change]);
        if ($audit) Audit::recordFor($this->owner, 'payment.zelle_received', $entry, [], $entry->getAttributes());
        return $entry->fresh();
    }
    private function allocateInput(int $id, int $ledgerId): array
    {
        $c = $this->candidates($id); $row = collect($c['rows'])->firstWhere('ledger_entry_id', $ledgerId);
        return ['opening_id' => $c['opening_id'], 'expected_collection_sha256' => $c['expected_collection_sha256'],
            'expected_receipt_sha256' => $row['receipt_sha256'], 'post_cutover_received' => true];
    }

    public function test_real_zelle_service_credits_only_new_verified_money_and_keeps_original_capacity_version(): void
    {
        $id = $this->ready(); $before = [];
        foreach (['events', 'event_services', 'resource_reservations', 'booking_evidence', 'imported_project_revisions', 'imported_ledger_lines'] as $t) $before[$t] = DB::table($t)->get()->toJson();
        $entry = $this->pay($id); $s = $this->summary($id);
        $this->assertSame('honeybook_opening', $s['source']); $this->assertSame(2000, $entry->amount_cents);
        $this->assertSame([7000, 1000, 6000, 2000, 8000, 4000, 0], array_map(fn ($k) => $s[$k], ['source_gross_received_cents',
            'source_refund_cents', 'source_net_received_cents', 'crm_received_cents', 'net_received_cents', 'balance_cents', 'deposit_shortfall_cents']));
        $this->assertFalse($s['collection_blocked']); $this->assertFalse($s['funds_verified']);
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('opening_receipt_allocations', 1);
        $this->assertDatabaseCount('mail_occurrences', 0); $this->assertDatabaseCount('outbound_messages', 0);
        $this->assertSame(Event::find($id)->version, FinancialRevision::sole()->event_version);
        foreach ($before as $t => $bytes) $this->assertSame($bytes, DB::table($t)->get()->toJson(), $t);
        $alloc = DB::table('opening_receipt_allocations')->get()->toJson();
        foreach (['PRIVATE_BANK_REFERENCE', 'PRIVATE_BANK_NOTE', 'PRIVATE_SOURCE_REFERENCE'] as $private) $this->assertStringNotContainsString($private, $alloc);
        Queue::assertNothingPushed(); Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_prepared_opening_and_manual_enum_change_do_not_authorize_source_money(): void
    {
        $link = $this->openingMember(); $this->prepareOpeningSet(); Event::find($link->event_id)->forceFill(['responsible_system' => 'crm'])->save();
        $this->assertNull(EffectiveOpeningAgreement::find(Event::find($link->event_id)));
        $this->postJson('/admin/api/events/'.$link->event_id.'/payments/zelle', ['financial_revision_id' => 1, 'amount_cents' => 2000, 'currency' => 'USD',
            'reference' => 'Synthetic bank', 'received_at' => now()->toIso8601String(), 'verified_received' => true], ['Idempotency-Key' => (string)Str::uuid()])->assertStatus(409);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->postJson('/admin/api/cutover/transfers')->assertNotFound();
    }
    public function test_receipt_retries_and_same_bank_reference_never_duplicate_source_or_crm_money(): void
    {
        $id = $this->ready(); $key = (string)Str::uuid();
        $first = $this->pay($id, key: $key);
        $this->assertSame($first->id, $this->pay($id, key: $key)->id);
        $this->assertSame($first->id, $this->pay($id)->id);
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('opening_receipt_allocations', 1);
        $this->expectException(RecordWriteRejected::class); $this->pay($id, ['amount_cents' => 2500], $key);
    }
    public function test_pre_cutover_timestamp_or_missing_explicit_source_decision_is_refused(): void
    {
        $id = $this->ready();
        try { $this->pay($id, ['received_at' => now()->subMinute()->toIso8601String()]); $this->fail('Source amount counted as new money.'); }
        catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        $input = $this->sourcePaymentInput($id); unset($input['post_cutover_received']);
        $this->postJson('/admin/api/events/'.$id.'/payments/zelle', $input, ['Idempotency-Key' => (string)Str::uuid()])->assertStatus(409);
        $this->assertDatabaseCount('ledger_entries', 0);
    }
    public function test_valid_effective_agreement_does_not_expire_when_original_reconciliation_becomes_old(): void
    {
        $id = $this->ready(); $this->travel(5)->hours(); $this->pay($id);
        $this->assertSame(4000, $this->summary($id)['balance_cents']);
    }
    public function test_changed_contact_or_capacity_blocks_manual_receipt_without_erasing_existing_money(): void
    {
        $id = $this->ready(); $this->pay($id);
        DB::table('customers')->where('id', Event::find($id)->customer_id)->increment('version');
        $s = $this->summary($id); $this->assertTrue($s['collection_blocked']); $this->assertSame(4000, $s['balance_cents']);
        $this->expectException(RecordWriteRejected::class); $this->pay($id, ['reference' => 'second reference']);
    }
    public function test_incomplete_group_or_changed_clear_effective_time_cannot_be_used(): void
    {
        $one = $this->openingMember(); $two = $this->openingMember('RECEIPT-FAKE-2');
        $transfer = $this->seedFutureActivation($this->prepareOpeningSet());
        DB::table('cutover_active_openings')->where('event_id', $two->event_id)->delete();
        $this->assertNull($this->summary($one->event_id)['balance_cents']);
        try { $this->sourcePaymentInput($one->event_id); $this->fail('Partial activation accepted.'); }
        catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        DB::table('cutover_active_openings')->insert(['event_id' => $two->event_id, 'cutover_financial_binding_id' => CutoverFinancialBinding::where('event_id', $two->event_id)->value('id')]);
        DB::table('cutover_transfers')->where('id', $transfer->id)->update(['effective_at' => now()->subDays(3)]);
        $this->assertTrue($this->summary($one->event_id)['collection_blocked']);
    }
    public function test_unknown_unallocated_refund_and_stripe_money_leave_balance_unknown(): void
    {
        $id = $this->ready(); $this->detachedReceipt($id, ['method' => 'stripe', 'source_type' => 'verified_stripe_webhook']);
        $s = $this->summary($id); $this->assertNull($s['balance_cents']); $this->assertNull($s['net_received_cents']);
        $this->assertSame(6000, $s['known_net_received_cents']); $this->assertTrue($s['collection_blocked']);
        $v = $this->candidates($id); $this->assertFalse($v['rows'][0]['eligible']); $this->assertContains('stripe_allocation_pending', $v['rows'][0]['issue_codes']);
        $this->assertStringNotContainsString('PRIVATE_DETACHED', json_encode($v));
    }
    public function test_existing_verified_zelle_can_be_linked_explicitly_and_exact_retry_is_immutable(): void
    {
        $id = $this->ready(); $entry = $this->detachedReceipt($id); $input = $this->allocateInput($id, $entry->id); $key = (string)Str::uuid();
        $this->assertNull($this->summary($id)['balance_cents']);
        $result = app(AllocateOpeningReceipt::class)->execute($this->owner, $id, $entry->id, $input, $key);
        $this->assertSame($result, app(AllocateOpeningReceipt::class)->execute($this->owner, $id, $entry->id, $input, $key));
        $this->assertSame($result, app(AllocateOpeningReceipt::class)->execute($this->owner, $id, $entry->id, $input, (string)Str::uuid()));
        $this->assertSame(5000, $this->summary($id)['balance_cents']); $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertDatabaseCount('opening_receipt_allocations', 1);
    }
    public function test_missing_bank_audit_or_changed_ledger_facts_cannot_be_overridden_by_checkbox(): void
    {
        $id = $this->ready(); $entry = $this->detachedReceipt($id, audit: false); $input = $this->allocateInput($id, $entry->id);
        $this->expectException(RecordWriteRejected::class);
        app(AllocateOpeningReceipt::class)->execute($this->owner, $id, $entry->id, $input, (string)Str::uuid());
    }
    public function test_tampered_allocation_blocks_balance_but_keeps_the_real_receipt(): void
    {
        $id = $this->ready(); $this->pay($id); DB::table('opening_receipt_allocations')->update(['payload' => 'CORRUPT_PRIVATE_ALLOCATION']);
        $s = $this->summary($id); $this->assertNull($s['balance_cents']); $this->assertTrue($s['collection_blocked']);
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertSame(2000, LedgerEntry::sole()->amount_cents);
        $this->assertStringNotContainsString('CORRUPT_PRIVATE_ALLOCATION', json_encode($s));
    }
    public function test_audit_failure_rolls_back_new_bank_receipt_allocation_and_idempotency_receipt(): void
    {
        $id = $this->ready(); $input = $this->sourcePaymentInput($id); $receiptCount = DB::table('mutation_receipts')->count(); $fail = true;
        DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$fail) {
            if ($fail && str_contains($sql, 'audit_entries') && in_array('opening.receipt_allocated', $bindings, true)) throw new \RuntimeException('Synthetic allocation audit failure');
        });
        try {
            try { app(RecordZellePayment::class)->execute($this->owner, $id, $input, (string)Str::uuid()); $this->fail('Missing rollback.'); }
            catch (\RuntimeException $e) { $this->assertSame('Synthetic allocation audit failure', $e->getMessage()); }
        } finally { $fail = false; }
        $this->assertDatabaseCount('ledger_entries', 0); $this->assertDatabaseCount('opening_receipt_allocations', 0);
        $this->assertSame($receiptCount, DB::table('mutation_receipts')->count());
    }
    public function test_unknown_due_day_fully_paid_source_has_no_fake_deadline_or_deposit_demand(): void
    {
        $link = $this->openingMember(change: ['amount_paid' => '120.00', 'balance' => '0.00', 'balance_due_date' => '', 'payments' => '2026-10-01|120.00|Zelle'], review: ['crm_balance_due_at' => null]);
        $this->seedFutureActivation($this->prepareOpeningSet()); $s = $this->summary($link->event_id);
        $this->assertNull($s['financial_revision']->balance_due_at); $this->assertNull($s['crm_balance_due_at']);
        $this->assertSame(0, $s['balance_cents']); $this->assertSame(0, $s['deposit_shortfall_cents']);
    }
    public function test_two_actual_owners_record_one_bank_identity_and_one_allocation(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('MariaDB/pcntl required.');
        $id = $this->ready(); $input = $this->sourcePaymentInput($id); $owner = $this->owner;
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $r = ConcurrentWorkers::run([fn () => app(RecordZellePayment::class)->execute($owner, $id, $input, (string)Str::uuid())->id,
            fn () => app(RecordZellePayment::class)->execute($other, $id, $input, (string)Str::uuid())->id]);
        $this->assertIsInt($r[0]); $this->assertSame($r[0], $r[1]);
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('opening_receipt_allocations', 1);
    }
    public function test_owner_http_source_fields_privacy_and_staging_csrf_gates(): void
    {
        $id = $this->ready();
        $this->postJson('/admin/api/events/'.$id.'/payments/zelle', $this->sourcePaymentInput($id), ['Idempotency-Key' => (string)Str::uuid()])->assertCreated();
        $response = $this->getJson('/admin/api/events/'.$id.'/opening-receipts')->assertOk()->assertDontSee('PRIVATE_BANK');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        config(['waoo.sandbox_cutover_bridge' => false]); $this->getJson('/admin/api/events/'.$id.'/opening-receipts')->assertForbidden();
        config(['waoo.sandbox_cutover_bridge' => true]); $this->app->detectEnvironment(fn () => 'production');
        $this->getJson('/admin/api/events/'.$id.'/opening-receipts')->assertForbidden();
        $this->app->detectEnvironment(fn () => 'staging'); config(['session.secure' => true]);
        $this->getJson('http://localhost/admin/api/events/'.$id.'/opening-receipts')->assertForbidden();
        $this->getJson('https://localhost/admin/api/events/'.$id.'/opening-receipts')->assertOk();
        $this->postJson('https://localhost/admin/api/events/'.$id.'/opening-receipts/1/allocate', [])->assertStatus(419);
    }
    public function test_old_repeatable_read_snapshot_restarts_outer_transaction_and_replays_an_independent_receipt(): void
    {
        if (DB::getDriverName() !== 'mysql') $this->markTestSkipped('MariaDB current reads required.');
        $id = $this->ready(); $input = $this->sourcePaymentInput($id); $key = (string)Str::uuid();
        $primary = DB::getDefaultConnection(); config(['database.connections.receipt_writer' => config('database.connections.'.$primary)]);
        $written = null;
        try {
            DB::transaction(function () use ($primary, $id, $input, $key, &$written) {
                $seen = DB::table('ledger_entries')->count(); // Establish an intentionally old RR snapshot on the first attempt.
                if ($written === null) {
                    $this->assertSame(0, $seen);
                    try { DB::setDefaultConnection('receipt_writer'); $written = app(RecordZellePayment::class)->execute($this->owner, $id, $input, $key); }
                    finally { DB::setDefaultConnection($primary); }
                }
                $again = app(RecordZellePayment::class)->execute($this->owner, $id, $input, $key);
                $this->assertSame($written->id, $again->id);
                $this->assertSame(4000, $this->summary($id)['balance_cents']);
            }, 3); // MariaDB 1020 requires retry of the whole transaction, never only a nested savepoint.
        } finally { DB::purge('receipt_writer'); DB::setDefaultConnection($primary); }
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('opening_receipt_allocations', 1);
    }
    public function test_two_different_source_events_can_record_distinct_bank_receipts_in_parallel(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('MariaDB/pcntl required.');
        $one = $this->openingMember(); $two = $this->openingMember('RECEIPT-FAKE-2'); $this->seedFutureActivation($this->prepareOpeningSet());
        $a = $this->sourcePaymentInput($one->event_id); $b = $this->sourcePaymentInput($two->event_id, ['reference' => 'PRIVATE_SECOND_BANK_REFERENCE']); $owner = $this->owner;
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $worker = fn ($actor, $eventId, $input) => app(RecordZellePayment::class)->execute($actor, $eventId, $input, (string)Str::uuid())->id;
        $results = ConcurrentWorkers::run([fn () => $worker($owner, $one->event_id, $a), fn () => $worker($other, $two->event_id, $b)]);
        $this->assertIsInt($results[0], json_encode($results)); $this->assertIsInt($results[1], json_encode($results)); $this->assertNotSame($results[0], $results[1]);
        $this->assertDatabaseCount('ledger_entries', 2); $this->assertDatabaseCount('opening_receipt_allocations', 2);
    }
    public function test_owning_another_event_does_not_allow_reallocating_its_bank_entry(): void
    {
        $one = $this->openingMember(); $two = $this->openingMember('RECEIPT-FAKE-2'); $this->seedFutureActivation($this->prepareOpeningSet());
        $entry = $this->pay($one->event_id); $input = $this->allocateInput($one->event_id, $entry->id);
        $this->postJson('/admin/api/events/'.$two->event_id.'/opening-receipts/'.$entry->id.'/allocate', $input,
            ['Idempotency-Key' => (string)Str::uuid()])->assertStatus(409);
        $this->assertSame(4000, $this->summary($one->event_id)['balance_cents']); $this->assertSame(6000, $this->summary($two->event_id)['balance_cents']);
    }
    public function test_overpayment_is_credit_and_requires_review_without_a_new_deposit_or_mail(): void
    {
        $id = $this->ready(); $this->pay($id, ['amount_cents' => 6500]); $s = $this->summary($id);
        $this->assertSame(0, $s['balance_cents']); $this->assertSame(500, $s['credit_cents']);
        $this->assertTrue($s['collection_blocked']); $this->assertContains('payment_exception_open', $s['opening_missing']);
        $this->assertDatabaseCount('mail_occurrences', 0);
    }
    public function test_tampered_bank_audit_invalidates_credit_and_missing_slot_does_not_restore_source_balance(): void
    {
        $id = $this->ready(); $entry = $this->pay($id);
        DB::table('audit_entries')->where('subject_type', 'ledger_entries')->where('subject_id', $entry->id)->update(['after' => json_encode(['amount_cents' => 1])]);
        $this->assertNull($this->summary($id)['balance_cents']);
        DB::table('cutover_active_openings')->delete();
        $s = $this->summary($id); $this->assertNull($s['balance_cents']); $this->assertTrue($s['collection_blocked']);
        $this->assertDatabaseCount('ledger_entries', 1);
    }
    public function test_source_fields_are_preserved_and_wrong_financial_components_fail_even_when_resealed(): void
    {
        $id = $this->ready(); $f = FinancialRevision::sole(); $binding = CutoverFinancialBinding::sole(); $transfer = CutoverTransfer::sole();
        $this->assertSame(10001, $f->subtotal_cents); $this->assertSame(2500, $f->travel_fee_cents); $this->assertSame(501, $f->discount_cents);
        DB::table('financial_revisions')->where('id', $f->id)->update(['travel_fee_cents' => 0]);
        $p = $binding->payload; $p['facts']['financial_sha256'] = \App\Services\Finance\MutationKey::hash($f->fresh()->toArray());
        $cipher = (new CutoverFinancialBinding)->forceFill(['payload' => $p])->getAttributes()['payload'];
        DB::table('cutover_financial_bindings')->where('id', $binding->id)->update(['payload' => $cipher, 'payload_sha256' => \App\Services\Finance\MutationKey::hash($p)]);
        $t = $transfer->payload; $t['members'][(string)$id] = \App\Services\Finance\MutationKey::hash($p['facts']);
        $cipher = (new CutoverTransfer)->forceFill(['payload' => $t])->getAttributes()['payload'];
        DB::table('cutover_transfers')->where('id', $transfer->id)->update(['payload' => $cipher, 'payload_sha256' => \App\Services\Finance\MutationKey::hash($t)]);
        $this->assertNull($this->summary($id)['balance_cents']);
    }
    public function test_sql_failure_does_not_expose_source_bank_note_and_same_key_can_retry_safely(): void
    {
        $id = $this->ready(); $input = $this->sourcePaymentInput($id); $key = (string)Str::uuid();
        Schema::rename('audit_entries', 'receipt_test_audit_off');
        try {
            $r = $this->postJson('/admin/api/events/'.$id.'/payments/zelle', $input, ['Idempotency-Key' => $key])->assertStatus(503);
            $r->assertDontSee('PRIVATE_BANK')->assertDontSee('SQL')->assertDontSee('receipt_test_audit_off');
        } finally { Schema::rename('receipt_test_audit_off', 'audit_entries'); }
        $this->assertDatabaseCount('ledger_entries', 0);
        app(RecordZellePayment::class)->execute($this->owner, $id, $input, $key);
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('opening_receipt_allocations', 1);
    }
    public function test_revoked_owner_cannot_replay_a_previously_valid_bank_command(): void
    {
        $id = $this->ready(); $input = $this->sourcePaymentInput($id); $key = (string)Str::uuid();
        app(RecordZellePayment::class)->execute($this->owner, $id, $input, $key);
        $this->owner->forceFill(['active' => false])->save();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(RecordZellePayment::class)->execute($this->owner, $id, $input, $key);
    }
    public function test_signed_test_stripe_receipt_survives_corrupt_opening_authority_without_false_balance(): void
    {
        $id = $this->ready(); $c = EffectiveOpeningAgreement::find(Event::find($id));
        $fake = new \Tests\Support\FakeStripeGateway(); $this->app->instance(\App\Services\Finance\StripeGateway::class, $fake);
        config(['waoo.stripe_test_account' => 'acct_fixture', 'waoo.stripe_test_webhook_secret' => 'whsec_fixture']);
        // Future-checkout fixture only: current public imported checkout remains forbidden.
        $intent = \App\Models\PaymentIntent::create(['id' => (string)Str::uuid(), 'event_id' => $id, 'financial_revision_id' => $c['finance']->id,
            'environment' => 'test', 'purpose' => 'balance', 'currency' => 'USD', 'principal_cents' => 2000, 'customer_fee_cents' => 0, 'payable_cents' => 2000,
            'status' => 'open', 'collection_slot' => 'event:'.$id, 'expires_at' => now()->addHour()]);
        $metadata = ['local_intent_id' => $intent->id, 'event_id' => (string)$id, 'financial_revision_id' => (string)$c['finance']->id];
        $session = $fake->createCheckout(['client_reference_id' => $intent->id, 'metadata' => $metadata, 'line_items' => [['price_data' => ['unit_amount' => 2000]]]], 'future-fixture');
        $intent->forceFill(['stripe_checkout_session_id' => $session['id']])->save(); $fake->paid($session['id']);
        DB::table('cutover_transfers')->update(['payload' => 'CORRUPT_TRANSFER_PROOF']);
        $raw = json_encode(['id' => 'evt_future_opening_fixture', 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => false,
            'created' => time(), 'data' => ['object' => ['id' => $session['id'], 'object' => 'checkout.session', 'metadata' => $metadata]]]);
        $stamp = time(); $sig = 't='.$stamp.',v1='.hash_hmac('sha256', $stamp.'.'.$raw, 'whsec_fixture');
        $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sig], $raw)->assertOk();
        app(\App\Services\Finance\StripeReceiptProcessor::class)->execute('evt_future_opening_fixture');
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertSame(2000, LedgerEntry::sole()->amount_cents);
        $this->assertSame('processed', \App\Models\WebhookReceipt::sole()->state); $this->assertSame('paid', $intent->fresh()->status);
        $this->assertNull($this->summary($id)['balance_cents']); $this->assertDatabaseCount('opening_receipt_allocations', 0);
    }
    public function test_a_resealed_backdated_transfer_cannot_authorize_a_receipt(): void
    {
        $id = $this->ready(); $input = $this->sourcePaymentInput($id);
        $transfer = \App\Models\CutoverTransfer::sole(); $p = $transfer->payload;
        $p['effective_at'] = now()->subSecond()->toIso8601String();
        DB::table('cutover_transfers')->where('id', $transfer->id)->update(['effective_at' => now()->subSecond(),
            'payload' => (new CutoverTransfer)->forceFill(['payload' => $p])->getAttributes()['payload'], 'payload_sha256' => MutationKey::hash($p)]);
        $this->assertSame($p, $transfer->fresh()->payload); // Valid envelope: rejection is semantic, not decryption failure.
        $this->postJson('/admin/api/events/'.$id.'/payments/zelle', $input, ['Idempotency-Key' => (string)Str::uuid()])->assertStatus(409);
        $this->assertDatabaseCount('ledger_entries', 0);
    }
    public function test_corrupt_original_pdf_blocks_new_receipts_but_keeps_known_money(): void
    {
        $id = $this->ready(); $this->pay($id); $input = $this->sourcePaymentInput($id, ['reference' => 'PRIVATE_DIFFERENT_REFERENCE']);
        DB::table('cutover_original_documents')->update(['document_ciphertext' => 'broken']);
        $this->postJson('/admin/api/events/'.$id.'/payments/zelle', $input, ['Idempotency-Key' => (string)Str::uuid()])->assertStatus(409);
        $s = $this->summary($id); $this->assertSame(4000, $s['balance_cents']); $this->assertTrue($s['collection_blocked']);
        $this->assertContains('opening_original_document_invalid', $s['opening_missing']);
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('opening_receipt_allocations', 1);
    }
    public function test_accepted_original_total_difference_is_not_a_new_crm_fee_or_repriced_package(): void
    {
        $link = $this->openingMember(change: ['total' => '123.60', 'amount_paid' => '70.00', 'balance' => '53.60', 'payments' => '2026-10-01|70.00|Zelle'],
            commit: ['source_difference_accepted' => true, 'source_difference_note' => 'PRIVATE_OWNER_NOTE accepted original source total.']);
        $this->seedFutureActivation($this->prepareOpeningSet()); $this->pay($link->event_id);
        $summary = $this->summary($link->event_id); $finance = $summary['financial_revision'];
        $this->assertSame(360, $summary['source_commercial_difference_cents']); $this->assertSame(3360, $summary['balance_cents']);
        $this->assertSame(10001, $finance->subtotal_cents); $this->assertSame(2500, $finance->travel_fee_cents);
        $this->assertSame(501, $finance->discount_cents); $this->assertSame(12360, $finance->total_cents);
        $this->assertSame(0, $finance->tax_cents); $this->assertSame(0, $finance->customer_fee_cents);
        $this->assertSame('Historical Package + Extra', $finance->lines_snapshot[0]['name']);
        $this->assertSame(2000, LedgerEntry::sole()->amount_cents);
    }
    public function test_populated_bridge_migration_refuses_to_erase_authority_or_unknown_deadline(): void
    {
        $id = $this->ready(); $this->pay($id);
        $migration = require database_path('migrations/2026_10_19_470000_create_opening_receipt_bridge.php');
        try { $migration->down(); $this->fail('Populated bridge erased.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('forward migration', $e->getMessage()); }
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('opening_receipt_allocations', 1);
    }
}
