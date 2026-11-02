<?php
namespace Tests\Feature;

use App\Models\{Event, FinancialRevision, TipGrant, TipCheckoutIntent, TipReceipt, User, WebhookReceipt};
use App\Services\Agreements\{ConfirmedAgreement, ReadConfirmedAgreement};
use App\Services\Finance\{FinanceSummary, RecordZellePayment, StripeGateway, StripeReceiptProcessor};
use App\Services\Tips\{IssueTipGrant, RevealTipGrantLink, RevokeTipGrant, TipContext};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Mail, Queue, Schema};
use Illuminate\Support\Str;
use Tests\Support\{ConcurrentWorkers, EffectiveOpeningScenario, FakeStripeGateway};
use Tests\TestCase;

class ConfirmedAgreementTest extends TestCase
{
    use DatabaseMigrations, EffectiveOpeningScenario;
    private FakeStripeGateway $stripe;
    protected function setUp(): void
    {
        parent::setUp(); $this->futureScenario(); $this->withCredentials();
        config(['waoo.sandbox_cutover' => true, 'waoo.sandbox_cutover_bridge' => true, 'waoo.sandbox_tips' => true,
            'waoo.stripe_test_account' => 'acct_fixture', 'waoo.stripe_test_secret' => 'sk_test_fixture', 'waoo.stripe_test_webhook_secret' => 'whsec_fixture']);
        Http::preventStrayRequests(); Mail::fake(); $this->stripe = new FakeStripeGateway(); $this->app->instance(StripeGateway::class, $this->stripe);
    }
    protected function tearDown(): void
    {
        if ($this->app) {
            $this->app->detectEnvironment(fn () => 'testing');
            foreach (['tip_reconciliations', 'tip_refunds', 'tip_receipts', 'tip_checkout_intents', 'tip_portal_sessions', 'tip_grants',
                'opening_receipt_allocations', 'cutover_active_openings', 'cutover_financial_bindings', 'cutover_transfers',
                'cutover_opening_agreements', 'cutover_opening_sets', 'cutover_reconciliations', 'honeybook_cutover_reviews', 'cutover_original_documents',
                'payment_exceptions', 'ledger_entries', 'payment_intents', 'financial_revisions'] as $table) if (Schema::hasTable($table)) DB::table($table)->delete();
            if (Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        }
        parent::tearDown();
    }
    private function ready(): int
    {
        $link = $this->openingMember(); $this->seedFutureActivation($this->prepareOpeningSet());
        $this->travelTo(Event::findOrFail($link->event_id)->ends_at->addHour()); return $link->event_id;
    }
    private function issue(int $eventId, ?string $key = null): array
    {
        $a = app(ReadConfirmedAgreement::class)->execute($this->owner, $eventId);
        return app(IssueTipGrant::class)->execute($this->owner, $eventId, ['expected_event_version' => $a['event_version'],
            'financial_revision_id' => $a['financial_revision_id'], 'locale' => 'es'], $key ?? (string)Str::uuid());
    }
    private function reveal(int $grantId, ?string $key = null, array $change = []): array
    {
        $g = TipGrant::findOrFail($grantId);
        return app(RevealTipGrantLink::class)->execute($this->owner, $grantId,
            ['expected_version' => $g->version, 'expected_snapshot_sha256' => $g->snapshot_sha256, ...$change], $key ?? (string)Str::uuid());
    }
    private function enter(array $grant): string
    {
        auth()->logout(); $r = $this->postJson('/portal/tip/session', ['token' => parse_url($grant['link'], PHP_URL_FRAGMENT)])->assertOk();
        return $r->getCookie(TipContext::COOKIE)->getValue();
    }
    private function checkout(string $cookie)
    {
        $g = TipGrant::sole();
        return $this->withCookie(TipContext::COOKIE, $cookie)->withHeader('Idempotency-Key', (string)Str::uuid())
            ->postJson('/portal/api/tip/checkout', ['financial_revision_id' => $g->financial_revision_id, 'snapshot_sha256' => $g->snapshot_sha256, 'option' => 'pct_10']);
    }
    public function test_owner_projection_preserves_original_actual_version_and_discloses_no_private_source(): void
    {
        $id = $this->ready(); $before = DB::table('resource_reservations')->get()->toJson();
        $r = $this->getJson('/admin/api/events/'.$id.'/confirmed-agreement')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('origin', 'honeybook_opening')->assertJsonPath('total_cents', 12000)->assertJsonPath('source_due_precision', 'date')
            ->assertJsonPath('source_commercial_difference_cents', 0)->assertJsonPath('original_document.owner_attests_signed_original', true);
        $this->assertSame(Event::findOrFail($id)->version, FinancialRevision::sole()->event_version);
        foreach (['PRIVATE_', 'address', 'email', 'phone', 'ciphertext', 'token', 'link', 'reference', 'characters_text'] as $private) $r->assertDontSee($private);
        $this->assertSame($before, DB::table('resource_reservations')->get()->toJson()); $this->assertDatabaseCount('ledger_entries', 0);
    }
    public function test_prepared_or_enum_only_import_never_becomes_a_confirmed_crm_agreement(): void
    {
        $link = $this->openingMember(); $this->prepareOpeningSet(); Event::findOrFail($link->event_id)->forceFill(['responsible_system' => 'crm'])->save();
        $this->getJson('/admin/api/events/'.$link->event_id.'/confirmed-agreement')->assertConflict();
        $this->postJson('/admin/api/events/'.$link->event_id.'/tip-grants', ['expected_event_version' => 2, 'financial_revision_id' => 1, 'locale' => 'es'], ['Idempotency-Key' => (string)Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('tip_grants', 0); $this->assertDatabaseCount('financial_revisions', 0);
    }
    public function test_source_tip_uses_original_total_and_bank_receipts_do_not_change_its_unchanged_basis(): void
    {
        $id = $this->ready(); $key = (string)Str::uuid(); $grant = $this->issue($id, $key); $basis = TipGrant::sole()->snapshot_sha256;
        app(RecordZellePayment::class)->execute($this->owner, $id, $this->sourcePaymentInput($id), (string)Str::uuid());
        $this->assertSame($grant, $this->issue($id, $key)); $this->assertSame($grant, $this->reveal($grant['id']));
        $this->assertSame($basis, app(ReadConfirmedAgreement::class)->execute($this->owner, $id)['snapshot_sha256']);
        $cookie = $this->enter($grant);
        $this->withCookie(TipContext::COOKIE, $cookie)->getJson('/portal/api/tip')->assertOk()->assertJsonPath('tip.total', '$120.00')->assertJsonPath('tip.options.1.amount_cents', 1200)->assertDontSee('PRIVATE_');
        $this->assertSame(4000, app(FinanceSummary::class)->forEvent(Event::findOrFail($id))['balance_cents']);
    }
    public function test_source_tip_refuses_unknown_principal_money_without_reconstructing_or_losing_it(): void
    {
        $id = $this->ready(); $f = FinancialRevision::sole();
        \App\Models\LedgerEntry::create(['event_id' => $id, 'financial_revision_id' => $f->id, 'environment' => 'test', 'kind' => 'receipt', 'method' => 'stripe',
            'amount_cents' => 1000, 'currency' => 'USD', 'received_at' => now(), 'source_type' => 'verified_stripe_webhook', 'external_reference' => 'PRIVATE_FIXTURE_PROVIDER_REFERENCE', 'external_identity_key' => 'stripe:test:acct_fixture:pi_unallocated']);
        $this->getJson('/admin/api/events/'.$id.'/confirmed-agreement')->assertOk(); // A basis read is not permission to collect.
        $this->postJson('/admin/api/events/'.$id.'/tip-grants', ['expected_event_version' => Event::findOrFail($id)->version, 'financial_revision_id' => $f->id, 'locale' => 'es'], ['Idempotency-Key' => (string)Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('tip_grants', 0); $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertNull(app(FinanceSummary::class)->forEvent(Event::findOrFail($id))['balance_cents']);
    }
    public function test_recopy_exact_retry_is_audited_once_without_rotation_or_token_in_audit_or_receipt(): void
    {
        $id = $this->ready(); $issued = $this->issue($id); $g = TipGrant::sole(); $before = $g->getAttributes(); $key = (string)Str::uuid();
        $this->assertSame($issued, $this->reveal($g->id, $key)); $this->assertSame($issued, $this->reveal($g->id, $key));
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'tip.grant_link_revealed')->count());
        $this->assertSame($issued, $this->reveal($g->id)); $this->assertSame(2, DB::table('audit_entries')->where('action', 'tip.grant_link_revealed')->count());
        $this->assertSame($before, $g->fresh()->getAttributes()); $this->assertDatabaseCount('tip_grants', 1);
        $token = parse_url($issued['link'], PHP_URL_FRAGMENT);
        $this->assertStringNotContainsString($token, DB::table('audit_entries')->get()->toJson().DB::table('mutation_receipts')->get()->toJson());
        Mail::assertNothingSent(); $this->assertSame([], $this->stripe->calls);
    }
    public function test_recopy_does_not_return_a_cached_token_after_envelope_or_owner_changes(): void
    {
        $id = $this->ready(); $g = $this->issue($id); $key = (string)Str::uuid(); $this->reveal($g['id'], $key);
        $input = ['expected_version' => 1, 'expected_snapshot_sha256' => TipGrant::sole()->snapshot_sha256];
        DB::table('tip_grants')->update(['encrypted_token' => 'BROKEN_PRIVATE_ENVELOPE']);
        $this->postJson('/admin/api/tip-grants/'.$g['id'].'/reveal', $input, ['Idempotency-Key' => $key])->assertConflict()->assertDontSee('BROKEN_PRIVATE_ENVELOPE');
        $this->owner->forceFill(['active' => false])->save();
        $this->postJson('/admin/api/tip-grants/'.$g['id'].'/reveal', $input, ['Idempotency-Key' => $key])->assertForbidden();
    }
    public function test_recopy_refuses_stale_version_unknown_fields_and_changed_payload_under_same_key(): void
    {
        $id = $this->ready(); $g = $this->issue($id); $input = ['expected_version' => 1, 'expected_snapshot_sha256' => TipGrant::sole()->snapshot_sha256]; $url = '/admin/api/tip-grants/'.$g['id'].'/reveal'; $key = (string)Str::uuid();
        $this->postJson($url, [...$input, 'expected_version' => 2], ['Idempotency-Key' => (string)Str::uuid()])->assertConflict();
        $this->postJson($url, [...$input, 'event_id' => $id], ['Idempotency-Key' => (string)Str::uuid()])->assertUnprocessable();
        $this->postJson($url, $input, ['Idempotency-Key' => $key])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($url, [...$input, 'expected_snapshot_sha256' => str_repeat('0', 64)], ['Idempotency-Key' => $key])->assertConflict();
    }
    public function test_changed_source_contact_pdf_and_return_to_honeybook_each_deny_new_actions(): void
    {
        $id = $this->ready(); $grant = $this->issue($id); $key = (string)Str::uuid(); $this->reveal($grant['id'], $key);
        $input = ['expected_version' => 1, 'expected_snapshot_sha256' => TipGrant::sole()->snapshot_sha256]; $url = '/admin/api/tip-grants/'.$grant['id'].'/reveal';
        $e = Event::findOrFail($id); $customer = $e->customer; $name = $customer->name; $customer->forceFill(['name' => 'Changed PRIVATE_CONTACT'])->save();
        $this->postJson($url, $input, ['Idempotency-Key' => $key])->assertConflict();
        $customer->forceFill(['name' => $name])->save();
        DB::table('cutover_original_documents')->update(['document_ciphertext' => 'broken']);
        $this->postJson($url, $input, ['Idempotency-Key' => $key])->assertConflict();
        $e->forceFill(['responsible_system' => 'honeybook'])->save();
        $this->postJson($url, $input, ['Idempotency-Key' => $key])->assertConflict()->assertJsonPath('code', 'responsible_system_honeybook');
        $this->assertDatabaseCount('tip_grants', 1); $this->assertDatabaseCount('ledger_entries', 0);
    }
    public function test_revoked_and_expired_source_tip_links_cannot_be_copied_again(): void
    {
        $id = $this->ready(); $g = $this->issue($id); $key = (string)Str::uuid(); $this->reveal($g['id'], $key);
        $input = ['expected_version' => 1, 'expected_snapshot_sha256' => TipGrant::sole()->snapshot_sha256];
        app(RevokeTipGrant::class)->execute($this->owner, $g['id'], ['expected_version' => 1], (string)Str::uuid());
        $this->postJson('/admin/api/tip-grants/'.$g['id'].'/reveal', $input, ['Idempotency-Key' => $key])->assertConflict();
        $fresh = $this->issue($id); $this->travelTo(Event::findOrFail($id)->ends_at->addDays(14));
        $this->postJson('/admin/api/tip-grants/'.$fresh['id'].'/reveal', ['expected_version' => 1, 'expected_snapshot_sha256' => TipGrant::findOrFail($fresh['id'])->snapshot_sha256], ['Idempotency-Key' => (string)Str::uuid()])->assertConflict();
    }
    public function test_owner_read_feature_production_https_and_recopy_csrf_gates(): void
    {
        $id = $this->ready(); $grant = $this->issue($id); $url = '/admin/api/events/'.$id.'/confirmed-agreement';
        config(['waoo.sandbox_cutover_bridge' => false]); $this->getJson($url)->assertForbidden(); config(['waoo.sandbox_cutover_bridge' => true]);
        $this->app->detectEnvironment(fn () => 'production'); $this->getJson($url)->assertForbidden();
        $this->app->detectEnvironment(fn () => 'staging'); config(['session.secure' => true]);
        $this->getJson('http://localhost'.$url)->assertForbidden(); $this->getJson('https://localhost'.$url)->assertOk();
        $this->postJson('https://localhost/admin/api/tip-grants/'.$grant['id'].'/reveal', [])->assertStatus(419);
    }
    public function test_recopy_audit_failure_does_not_consume_the_key_and_retry_can_recover(): void
    {
        $id = $this->ready(); $grant = $this->issue($id); $key = (string)Str::uuid(); Schema::rename('audit_entries', 'tip_reveal_audit_off');
        try { $this->reveal($grant['id'], $key); $this->fail('Reveal did not fail with audit storage unavailable.'); }
        catch (\RuntimeException $e) { $this->assertSame('Private import persistence unavailable.', $e->getMessage()); $this->assertNull($e->getPrevious()); }
        finally { Schema::rename('tip_reveal_audit_off', 'audit_entries'); }
        $this->assertSame(0, DB::table('mutation_receipts')->where('request_key', $key)->count());
        $this->assertSame($grant, $this->reveal($grant['id'], $key)); $this->assertDatabaseCount('tip_grants', 1);
    }
    public function test_two_owners_recopy_one_current_link_without_rotation_or_duplicate_grants(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('MariaDB/pcntl required.');
        $id = $this->ready(); $grant = $this->issue($id); $g = TipGrant::sole(); $input = ['expected_version' => 1, 'expected_snapshot_sha256' => $g->snapshot_sha256];
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $owner = $this->owner;
        $results = ConcurrentWorkers::run([fn () => app(RevealTipGrantLink::class)->execute($owner, $g->id, $input, (string)Str::uuid())['link'],
            fn () => app(RevealTipGrantLink::class)->execute($other, $g->id, $input, (string)Str::uuid())['link']]);
        $this->assertSame([$grant['link'], $grant['link']], $results); $this->assertDatabaseCount('tip_grants', 1);
        $this->assertSame(2, DB::table('audit_entries')->where('action', 'tip.grant_link_revealed')->count());
    }
    public function test_source_tip_checkout_and_signed_receipt_stay_separate_from_original_booking_money(): void
    {
        $id = $this->ready(); $grant = $this->issue($id); $money = app(FinanceSummary::class)->forEvent(Event::findOrFail($id));
        $capacity = DB::table('resource_reservations')->get()->toJson(); $cookie = $this->enter($grant); $this->checkout($cookie)->assertCreated();
        $i = TipCheckoutIntent::sole(); $this->stripe->paid($i->provider_session_id);
        $this->webhook($i);
        (new \App\Jobs\ProcessStripeWebhook('evt_source_tip_fixture'))->handle(app(StripeReceiptProcessor::class));
        $this->assertSame(1200, TipReceipt::sole()->gross_cents); $this->assertSame('paid', $i->fresh()->status);
        $this->assertSame(json_encode($money), json_encode(app(FinanceSummary::class)->forEvent(Event::findOrFail($id))));
        $this->assertSame($capacity, DB::table('resource_reservations')->get()->toJson()); $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('mail_occurrences', 0); Mail::assertNothingSent();
    }
    public function test_actual_verified_late_tip_survives_changed_source_as_pending_reconciliation(): void
    {
        $id = $this->ready(); $cookie = $this->enter($this->issue($id)); $this->checkout($cookie)->assertCreated(); $i = TipCheckoutIntent::sole();
        DB::table('cutover_transfers')->update(['payload' => 'CORRUPT_PRIVATE_TRANSFER']); $this->checkout($cookie)->assertConflict();
        $this->stripe->paid($i->provider_session_id); $this->webhook($i);
        (new \App\Jobs\ProcessStripeWebhook('evt_source_tip_fixture'))->handle(app(StripeReceiptProcessor::class));
        $this->assertSame(1200, TipReceipt::sole()->gross_cents); $this->assertDatabaseHas('tip_reconciliations', ['reason' => 'late_tip_source_changed']);
        $this->assertSame('processed', WebhookReceipt::findOrFail('evt_source_tip_fixture')->state); $this->assertDatabaseCount('ledger_entries', 0);
    }
    private function webhook(TipCheckoutIntent $i): void
    {
        $s = $this->stripe->sessions[$i->provider_session_id]; $raw = json_encode(['id' => 'evt_source_tip_fixture', 'object' => 'event', 'type' => 'checkout.session.completed',
            'livemode' => false, 'created' => time(), 'data' => ['object' => ['id' => $s['id'], 'object' => 'checkout.session', 'payment_intent' => $s['payment_intent']['id'], 'metadata' => $s['metadata']]]]);
        $stamp = time(); $sig = 't='.$stamp.',v1='.hash_hmac('sha256', $stamp.'.'.$raw, 'whsec_fixture');
        $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sig], $raw)->assertOk();
    }
}
