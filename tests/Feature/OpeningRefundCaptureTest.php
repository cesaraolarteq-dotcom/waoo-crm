<?php
namespace Tests\Feature;

use App\Jobs\ProcessStripeWebhook;
use App\Models\{Event, FinancialRevision, LedgerEntry, OpeningRefundEvidence, OpeningRefundObservation, PaymentIntent, WebhookReceipt};
use App\Services\Finance\{AllocateOpeningReceipt, EffectiveOpeningAgreement, FinanceSummary, ReadOpeningReceiptCandidates, StripeGateway, StripeReceiptProcessor, StripeRefundGateway};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail, Schema};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\{ConcurrentWorkers, EffectiveOpeningScenario, FakeStripeGateway, FakeStripeRefundGateway};
use Tests\TestCase;

class OpeningRefundCaptureTest extends TestCase
{
    use DatabaseMigrations, EffectiveOpeningScenario;
    private FakeStripeRefundGateway $refunds;
    private LedgerEntry $parent;
    private int $eventId;
    protected function setUp(): void
    {
        parent::setUp(); $this->futureScenario();
        config(['waoo.sandbox_cutover' => true, 'waoo.sandbox_cutover_bridge' => true,
            'waoo.stripe_test_account' => 'acct_fixture', 'waoo.stripe_test_webhook_secret' => 'whsec_fixture']);
        Http::preventStrayRequests(); Mail::fake();
        $this->refunds = new FakeStripeRefundGateway(); $this->app->instance(StripeRefundGateway::class, $this->refunds);
        $link = $this->openingMember(change: ['client_first_name' => 'Daniela', 'client_last_name' => 'Pérez',
            'client_email' => 'daniela.perez@example.test', 'project_name' => 'Cumpleaños de Mateo']);
        $this->eventId = $link->event_id; $this->seedFutureActivation($this->prepareOpeningSet());
        $this->parent = $this->paidParent(); $this->travel(1)->minutes();
        $this->refunds->responses['re_daniela_1'] = $this->provider();
    }
    protected function tearDown(): void
    {
        if ($this->app) {
            $this->app->detectEnvironment(fn () => 'testing');
            foreach (['opening_refund_evidence', 'opening_refund_observations', 'opening_stripe_evidence', 'opening_receipt_allocations', 'cutover_active_openings',
                'cutover_financial_bindings', 'cutover_transfers', 'cutover_opening_agreements', 'cutover_opening_sets', 'cutover_reconciliations', 'honeybook_cutover_reviews',
                'cutover_original_documents', 'payment_exceptions', 'ledger_entries', 'payment_intents', 'financial_revisions'] as $t)
                if (Schema::hasTable($t)) DB::table($t)->delete();
            if (Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        }
        parent::tearDown();
    }
    private function paidParent(): LedgerEntry
    {
        $fake = new FakeStripeGateway(); $this->app->instance(StripeGateway::class, $fake);
        $c = EffectiveOpeningAgreement::find(Event::findOrFail($this->eventId));
        $i = PaymentIntent::create(['id' => (string)Str::uuid(), 'event_id' => $this->eventId, 'financial_revision_id' => $c['finance']->id,
            'environment' => 'test', 'purpose' => 'balance', 'currency' => 'USD', 'principal_cents' => 2000, 'customer_fee_cents' => 60,
            'payable_cents' => 2060, 'status' => 'open', 'expires_at' => now()->addHour()]);
        $metadata = ['local_intent_id' => $i->id, 'event_id' => (string)$this->eventId, 'financial_revision_id' => (string)$c['finance']->id];
        $s = $fake->createCheckout(['client_reference_id' => $i->id, 'metadata' => $metadata, 'line_items' => [['price_data' => ['unit_amount' => 2060]]]], 'daniela-parent');
        $i->forceFill(['stripe_checkout_session_id' => $s['id']])->save(); $fake->paid($s['id']);
        $fake->sessions[$s['id']]['payment_intent']['id'] = 'pi_daniela_parent';
        $fake->sessions[$s['id']]['payment_intent']['latest_charge']['created'] = now()->timestamp;
        $this->signedEvent('evt_parent_daniela', ['id' => $s['id'], 'object' => 'checkout.session', 'metadata' => $metadata], 'checkout.session.completed')->assertOk();
        app(StripeReceiptProcessor::class)->execute('evt_parent_daniela'); $entry = LedgerEntry::sole();
        $v = app(ReadOpeningReceiptCandidates::class)->execute($this->owner, $this->eventId);
        app(AllocateOpeningReceipt::class)->execute($this->owner, $this->eventId, $entry->id, ['opening_id' => $v['opening_id'],
            'expected_collection_sha256' => $v['expected_collection_sha256'], 'expected_receipt_sha256' => $v['rows'][0]['receipt_sha256'], 'post_cutover_received' => true], (string)Str::uuid());
        return $entry;
    }
    private function provider(): array
    {
        return ['account' => 'acct_fixture', 'refund' => ['id' => 're_daniela_1', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'usd',
            'created' => now()->timestamp, 'charge_id' => 'ch_daniela_1', 'payment_id' => $this->parent->external_reference],
            'charge' => ['id' => 'ch_daniela_1', 'payment_id' => $this->parent->external_reference, 'amount' => 2060, 'currency' => 'usd',
                'created' => $this->parent->received_at->timestamp, 'livemode' => false, 'paid' => true, 'captured' => true],
            'payment' => ['id' => $this->parent->external_reference, 'amount_received' => 2060, 'currency' => 'usd', 'livemode' => false, 'status' => 'succeeded'],
            'billing_details' => ['name' => 'PRIVATE_PROVIDER_CONTACT']];
    }
    private function signedEvent(string $id, array $object, string $type = 'refund.created', array $overrides = [], bool $signed = true)
    {
        $raw = json_encode([...['id' => $id, 'object' => 'event', 'type' => $type, 'livemode' => false, 'account' => 'acct_fixture', 'created' => time(),
            'data' => ['object' => $object]], ...$overrides], JSON_THROW_ON_ERROR);
        $stamp = time(); $signature = 't='.$stamp.',v1='.hash_hmac('sha256', $stamp.'.'.$raw, $signed ? 'whsec_fixture' : 'whsec_wrong');
        return $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $raw);
    }
    private function deliver(string $id = 'evt_refund_daniela', string $refundId = 're_daniela_1', bool $process = true, array $metadata = []): void
    {
        $this->signedEvent($id, ['id' => $refundId, 'object' => 'refund', 'payment_intent' => $this->parent->external_reference,
            'charge' => 'ch_daniela_1', 'metadata' => $metadata, 'billing_details' => ['name' => 'PRIVATE_CALLBACK_CONTACT'], 'description' => 'PRIVATE_CALLBACK_NOTE'])->assertOk();
        if ($process) $this->process($id);
    }
    private function process(string $id): void { (new ProcessStripeWebhook($id))->handle(app(StripeReceiptProcessor::class)); }
    private function summary(): array { return app(FinanceSummary::class)->forEvent(Event::findOrFail($this->eventId)); }

    public function test_signed_succeeded_refund_is_captured_privately_without_guessing_balance_credit(): void
    {
        $this->deliver(); $row = LedgerEntry::where('kind', 'refund')->sole(); $e = OpeningRefundEvidence::sole(); $o = OpeningRefundObservation::sole();
        $this->assertSame(500, $row->amount_cents); $this->assertSame('verified_stripe_refund', $row->source_type);
        $this->assertSame($this->parent->id, $e->parent_ledger_entry_id); $this->assertSame($row->id, $e->ledger_entry_id);
        $this->assertSame(1, $e->payload['schema_version']); $this->assertSame('refund_allocation_pending', $o->payload['reason']);
        $this->assertSame('processed', WebhookReceipt::find('evt_refund_daniela')->state);
        $s = $this->summary(); $this->assertNull($s['balance_cents']); $this->assertTrue($s['collection_blocked']);
        $this->assertSame(2000, $s['crm_received_cents']); $this->assertSame(60, $s['crm_customer_fee_cents']); $this->assertSame(0, $s['crm_refunded_cents']);
        $this->assertDatabaseCount('opening_receipt_allocations', 1); $this->assertDatabaseCount('outbound_messages', 0); $this->assertDatabaseCount('mail_occurrences', 0);
        $this->assertDatabaseCount('tip_refunds', 0); $this->assertDatabaseCount('tip_receipts', 0);
        $stored = DB::table('opening_refund_observations')->get()->toJson().DB::table('opening_refund_evidence')->get()->toJson().json_encode($e->payload);
        foreach (['PRIVATE_CALLBACK_CONTACT', 'PRIVATE_CALLBACK_NOTE', 'PRIVATE_PROVIDER_CONTACT', 'daniela.perez'] as $private) $this->assertStringNotContainsString($private, $stored);
        $minimal = Crypt::decryptString(WebhookReceipt::find('evt_refund_daniela')->encrypted_payload);
        $this->assertStringNotContainsString('PRIVATE_', $minimal);
        $this->assertStringNotContainsString('re_daniela', DB::table('opening_refund_evidence')->get()->toJson());
        Http::assertNothingSent(); Mail::assertNothingSent();
        $this->postJson('/admin/api/events/'.$this->eventId.'/opening-refunds/'.$row->id.'/allocate')->assertNotFound();
    }
    public function test_same_event_and_different_callback_retries_never_duplicate_the_refund(): void
    {
        $this->deliver(); $this->deliver(); $this->deliver('evt_refund_again');
        $this->assertDatabaseCount('opening_refund_evidence', 1); $this->assertDatabaseCount('opening_refund_observations', 2);
        $this->assertDatabaseCount('ledger_entries', 2); $this->assertSame('refund_duplicate', OpeningRefundObservation::latest('id')->first()->payload['reason']);
        $this->assertSame(1, DB::table('payment_exceptions')->where('kind', 'opening_refund_review')->count());
    }
    public static function providerFailures(): array
    {
        return ['account' => ['account', 'acct_other'], 'refund currency' => ['refund.currency', 'eur'], 'gross exceeds parent' => ['refund.amount', 3000],
            'no amount' => ['refund.amount', null], 'float amount' => ['refund.amount', 500.1], 'future' => ['refund.created', 4102444800],
            'before parent' => ['refund.created', 1], 'wrong refund' => ['refund.id', 're_other'], 'wrong charge' => ['refund.charge_id', 'ch_other'],
            'wrong payment' => ['refund.payment_id', 'pi_other'], 'live' => ['charge.livemode', true], 'uncaptured' => ['charge.captured', false],
            'unpaid payment' => ['payment.status', 'pending'], 'charge gross mismatch' => ['charge.amount', 2000]];
    }
    #[DataProvider('providerFailures')]
    public function test_invalid_provider_evidence_never_creates_a_financial_refund(string $field, mixed $value): void
    {
        data_set($this->refunds->responses['re_daniela_1'], $field, $value); $this->deliver();
        $this->assertDatabaseCount('opening_refund_evidence', 0); $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame('refund_provider_binding_invalid', OpeningRefundObservation::sole()->payload['reason']);
        if (OpeningRefundObservation::sole()->parent_ledger_entry_id) {
            $this->assertNull($this->summary()['balance_cents']);
            $this->assertDatabaseHas('payment_exceptions', ['event_id' => $this->eventId, 'kind' => 'opening_provider_review']);
        } else $this->assertSame(4000, $this->summary()['balance_cents']);
    }
    public static function unresolvedStates(): array { return [['pending'], ['failed'], ['canceled'], ['requires_action']]; }
    #[DataProvider('unresolvedStates')]
    public function test_unresolved_status_stays_observation_only_with_owner_review(string $status): void
    {
        $this->refunds->responses['re_daniela_1']['refund']['status'] = $status; $this->deliver();
        $this->assertDatabaseCount('opening_refund_evidence', 0); $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame('refund_'.$status, OpeningRefundObservation::sole()->payload['reason']);
        $this->assertSame(4000, $this->summary()['balance_cents']); $this->assertTrue($this->summary()['collection_blocked']);
    }
    public function test_pending_then_success_keeps_both_observations_without_rewriting_history(): void
    {
        $this->refunds->responses['re_daniela_1']['refund']['status'] = 'pending'; $this->deliver(); $first = OpeningRefundObservation::sole()->getRawOriginal('payload');
        $this->refunds->responses['re_daniela_1']['refund']['status'] = 'succeeded'; $this->deliver('evt_refund_success');
        $this->assertDatabaseCount('opening_refund_evidence', 1); $this->assertDatabaseCount('opening_refund_observations', 2);
        $this->assertSame($first, OpeningRefundObservation::oldest('id')->first()->getRawOriginal('payload'));
    }
    public function test_conflicting_successful_callback_keeps_original_money_and_evidence(): void
    {
        $this->deliver(); $before = OpeningRefundEvidence::sole()->getRawOriginal('payload');
        $this->refunds->responses['re_daniela_1']['refund']['amount'] = 600; $this->deliver('evt_refund_conflicting');
        $this->assertSame('refund_identity_conflict', OpeningRefundObservation::latest('id')->first()->payload['reason']);
        $this->assertSame(500, LedgerEntry::where('kind', 'refund')->sole()->amount_cents); $this->assertSame($before, OpeningRefundEvidence::sole()->getRawOriginal('payload'));
    }
    public function test_actual_refund_survives_broken_transfer_and_missing_parent_proof_without_becoming_allocated(): void
    {
        DB::table('cutover_transfers')->update(['payload' => 'damaged transfer']); DB::table('opening_stripe_evidence')->delete();
        $this->deliver(); $this->assertDatabaseCount('opening_refund_evidence', 1);
        $this->assertNull(OpeningRefundEvidence::sole()->payload['parent_stripe_evidence_sha256']);
        $this->assertDatabaseCount('ledger_entries', 2); $this->assertNull($this->summary()['balance_cents']);
    }
    public function test_unlinked_provider_payment_keeps_observation_without_inventing_source_parent(): void
    {
        $p = &$this->refunds->responses['re_daniela_1'];
        $p['refund']['payment_id'] = $p['charge']['payment_id'] = $p['payment']['id'] = 'pi_unlinked_maria';
        $this->signedEvent('evt_unlinked', ['id' => 're_daniela_1', 'object' => 'refund', 'payment_intent' => 'pi_unlinked_maria', 'charge' => 'ch_daniela_1'])->assertOk(); $this->process('evt_unlinked');
        $this->assertSame('refund_source_parent_required', OpeningRefundObservation::sole()->payload['reason']);
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('opening_refund_evidence', 0);
    }
    public static function nativeParents(): array { return [[false], [true]]; }
    #[DataProvider('nativeParents')]
    public function test_verified_native_parent_is_not_reclassified_as_imported_principal(bool $knownIntent): void
    {
        $native = Event::findOrFail($this->eventId)->replicate(); $native->title = 'Fiesta de Valentina'; $native->save();
        $finance = FinancialRevision::where('event_id', $this->eventId)->sole()->replicate(); $finance->event_id = $native->id; $finance->save();
        $this->parent = $this->parent->replicate(); $this->parent->fill(['event_id' => $native->id, 'financial_revision_id' => $finance->id,
            'external_reference' => 'pi_native_valentina', 'external_identity_key' => 'stripe:test:acct_fixture:pi_native_valentina'])->save();
        if ($knownIntent) {
            $i = PaymentIntent::where('event_id', $this->eventId)->sole()->replicate();
            $i->fill(['id' => (string)Str::uuid(), 'event_id' => $native->id, 'financial_revision_id' => $finance->id,
                'stripe_checkout_session_id' => 'cs_native_valentina', 'stripe_payment_intent_id' => 'pi_native_valentina'])->save();
        }
        $this->refunds->responses['re_daniela_1'] = $this->provider();
        $before = app(FinanceSummary::class)->forEvent($native); $this->deliver();
        $this->assertDatabaseCount('ledger_entries', 2); $this->assertDatabaseCount('opening_refund_evidence', 0);
        if ($knownIntent) {
            $this->assertDatabaseCount('opening_refund_observations', 0);
            $this->assertDatabaseHas('payment_exceptions', ['event_id' => $native->id, 'kind' => 'stripe_reconciliation']);
        } else $this->assertNull(OpeningRefundObservation::sole()->parent_ledger_entry_id);
        $this->assertSame($before['balance_cents'], app(FinanceSummary::class)->forEvent($native)['balance_cents']);
    }
    public function test_metadata_only_tip_claim_cannot_redirect_a_verified_principal_refund(): void
    {
        $this->deliver(metadata: ['kind' => 'tip', 'local_tip_intent_id' => (string)Str::uuid()]);
        $this->assertDatabaseCount('opening_refund_evidence', 1); $this->assertDatabaseCount('tip_refunds', 0); $this->assertDatabaseCount('tip_reconciliations', 0);
    }
    public function test_provider_transport_error_is_private_and_same_callback_can_retry(): void
    {
        $this->refunds->fail = true; $this->deliver(process: false);
        try { $this->process('evt_refund_daniela'); $this->fail('Expected provider error'); }
        catch (\RuntimeException $e) { $this->assertSame('Private Stripe refund verification unavailable.', $e->getMessage()); $this->assertNull($e->getPrevious()); }
        $this->assertDatabaseCount('opening_refund_observations', 0); $this->assertSame('processing_retry_required', WebhookReceipt::find('evt_refund_daniela')->last_error);
        $this->refunds->fail = false; $this->process('evt_refund_daniela'); $this->assertDatabaseCount('opening_refund_evidence', 1);
    }
    public function test_storage_failure_is_atomic_then_same_callback_recovers(): void
    {
        $fail = true; OpeningRefundEvidence::creating(function () use (&$fail) { if ($fail) throw new \RuntimeException('Synthetic storage failure'); });
        try {
            $this->deliver(process: false);
            try { $this->process('evt_refund_daniela'); $this->fail('Expected storage failure'); }
            catch (\RuntimeException $e) { $this->assertSame('Synthetic storage failure', $e->getMessage()); }
            $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('opening_refund_observations', 0);
            $this->assertSame('queued', WebhookReceipt::find('evt_refund_daniela')->state); $fail = false;
            $this->process('evt_refund_daniela'); $this->assertDatabaseCount('opening_refund_evidence', 1);
        } finally { $fail = false; }
    }
    public function test_independent_concurrent_callback_workers_capture_one_actual_refund(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires MariaDB and pcntl.');
        $this->deliver(process: false); $this->deliver('evt_refund_second', process: false);
        $r = ConcurrentWorkers::run([function () { $this->process('evt_refund_daniela'); return 'captured'; }, function () { $this->process('evt_refund_second'); return 'captured'; }]);
        $this->assertSame(['captured','captured'], $r); $this->assertDatabaseCount('opening_refund_evidence', 1);
        $this->assertDatabaseCount('opening_refund_observations', 2); $this->assertDatabaseCount('ledger_entries', 2);
    }
    public function test_multiple_actual_partial_refunds_over_gross_are_retained_but_not_credited(): void
    {
        $this->refunds->responses['re_daniela_1']['refund']['amount'] = 1500; $this->deliver();
        $p = $this->provider(); $p['refund']['id'] = 're_daniela_2'; $p['refund']['amount'] = 1500;
        $this->refunds->responses['re_daniela_2'] = $p; $this->deliver('evt_second_partial', 're_daniela_2');
        $this->assertDatabaseCount('opening_refund_evidence', 2); $this->assertSame(3000, (int)LedgerEntry::where('kind', 'refund')->sum('amount_cents'));
        $this->assertSame('refund_total_exceeds_parent', OpeningRefundObservation::latest('id')->first()->payload['reason']);
        $this->assertNull($this->summary()['balance_cents']);
    }
    public function test_success_then_failed_alias_keeps_evidence_and_forces_unknown_money(): void
    {
        $this->deliver(); $before = OpeningRefundEvidence::sole()->getRawOriginal('payload');
        $this->refunds->responses['re_daniela_1']['refund']['status'] = 'failed';
        $this->signedEvent('evt_refund_failed_later', ['id' => 're_daniela_1', 'object' => 'refund', 'payment_intent' => $this->parent->external_reference, 'charge' => 'ch_daniela_1'], 'charge.refund.updated')->assertOk();
        $this->process('evt_refund_failed_later');
        $this->assertSame($before, OpeningRefundEvidence::sole()->getRawOriginal('payload'));
        $this->assertSame('refund_failed', OpeningRefundObservation::latest('id')->first()->payload['reason']);
        $this->assertDatabaseHas('payment_exceptions', ['event_id' => $this->eventId, 'kind' => 'opening_provider_review', 'state' => 'open']);
        $this->assertNull($this->summary()['balance_cents']); $this->assertContains('provider_financial_review_required', $this->summary()['opening_missing']);
    }
    public static function chargeNotices(): array
    { return [['charge.refunded'], ['charge.dispute.created'], ['charge.dispute.closed']]; }
    #[DataProvider('chargeNotices')]
    public function test_verified_charge_notices_block_money_without_inventing_refund_rows(string $type): void
    {
        $p = $this->provider(); unset($p['refund']); $this->refunds->charges['ch_daniela_1'] = $p;
        $o = $type === 'charge.refunded' ? ['id' => 'ch_daniela_1', 'object' => 'charge'] : ['id' => 'dp_daniela_1', 'object' => 'dispute', 'charge' => 'ch_daniela_1'];
        $o['payment_intent'] = $this->parent->external_reference; $this->signedEvent('evt_charge_notice', $o, $type)->assertOk(); $this->process('evt_charge_notice');
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('opening_refund_evidence', 0);
        $this->assertSame($type === 'charge.refunded' ? 'charge_refund_details_pending' : 'charge_dispute_review_required', OpeningRefundObservation::sole()->payload['reason']);
        $s = $this->summary(); $this->assertNull($s['balance_cents']); $this->assertNull($s['net_received_cents']); $this->assertTrue($s['collection_blocked']);
        $this->assertSame(8000, $s['known_net_received_cents']); $this->assertSame(60, $s['crm_customer_fee_cents']);
        $this->assertDatabaseHas('payment_exceptions', ['event_id' => $this->eventId, 'kind' => 'opening_provider_review']);
        $this->process('evt_charge_notice'); $this->assertDatabaseCount('opening_refund_observations', 1);
    }
    public function test_unknown_refund_amount_on_a_verified_parent_forces_unknown_balance(): void
    {
        $this->refunds->responses['re_daniela_1']['refund']['amount'] = null; $this->deliver();
        $this->assertSame($this->parent->id, OpeningRefundObservation::sole()->parent_ledger_entry_id);
        $this->assertDatabaseCount('opening_refund_evidence', 0); $this->assertNull($this->summary()['balance_cents']);
        $this->assertTrue($this->summary()['collection_blocked']);
    }
    public function test_unverified_charge_notice_cannot_bind_or_modify_principal_money(): void
    {
        $p = $this->provider(); unset($p['refund']); $p['account'] = 'acct_other'; $this->refunds->charges['ch_daniela_1'] = $p;
        $this->signedEvent('evt_charge_wrong_acct', ['id' => 'ch_daniela_1', 'object' => 'charge', 'payment_intent' => $this->parent->external_reference], 'charge.refunded')->assertOk();
        $this->process('evt_charge_wrong_acct'); $this->assertNull(OpeningRefundObservation::sole()->parent_ledger_entry_id);
        $this->assertSame('charge_provider_binding_invalid', OpeningRefundObservation::sole()->payload['reason']);
        $this->assertSame(4000, $this->summary()['balance_cents']);
    }
    public function test_signature_account_mode_and_malformed_refund_are_rejected_before_provider_read(): void
    {
        $o = ['id' => 're_daniela_1', 'object' => 'refund'];
        $this->signedEvent('evt_wrong_sig', $o, signed: false)->assertStatus(400);
        $this->signedEvent('evt_wrong_acct', $o, overrides: ['account' => 'acct_other'])->assertStatus(400);
        $this->signedEvent('evt_live', $o, overrides: ['livemode' => true])->assertStatus(400);
        $this->signedEvent('evt_wrong_type', ['id' => 'cs_wrong', 'object' => 'checkout.session'])->assertStatus(400);
        $this->assertSame([], $this->refunds->calls); $this->assertDatabaseCount('opening_refund_observations', 0);
    }
    public function test_empty_migration_roundtrip_and_populated_rollback_and_model_mutation_refusal(): void
    {
        $m = require database_path('migrations/2026_10_20_510000_create_opening_refund_evidence.php'); $m->down(); $m->up(); $this->deliver();
        try { $m->down(); $this->fail('Destroyed actual refund evidence'); } catch (\RuntimeException $e) { $this->assertStringContainsString('forward migration', $e->getMessage()); }
        try { OpeningRefundObservation::sole()->update(['payload_sha256' => str_repeat('0', 64)]); $this->fail('Mutable observation'); }
        catch (\LogicException $e) { $this->assertStringContainsString('immutable', $e->getMessage()); }
        $this->assertDatabaseCount('opening_refund_evidence', 1);
    }
}
