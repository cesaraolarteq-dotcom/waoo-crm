<?php

namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Character, Customer, Event, FinancialRevision, PriceVersion, QuickStripeCheckoutRequest, QuickStripeLease, Resource, ServiceVariant, User};
use App\Services\Booking\{PrepareQuickStripeCheckoutRequest, PrepareQuickStripeLease, ReadQuickStripeCheckoutRequest, ReleaseQuickStripeLease};
use App\Services\Finance\{CreateFinancialRevision, MutationKey, StripeGateway};
use App\Services\Records\SaveEventDraft;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{Crypt, DB, Http, Schema};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class QuickStripeCheckoutRequestTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private Event $event;
    private FinancialRevision $finance;
    private array $lease;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20T12:00:00-05:00'));
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'waoo.sandbox_quick_stripe' => true,
            'waoo.stripe_test_account' => 'acct_syntheticWaoo', 'waoo.quick_stripe_requested_methods' => ['card'], 'app.url' => 'https://crm.example.test']);
        Http::preventStrayRequests();
        $gateway = \Mockery::mock(StripeGateway::class);
        $gateway->shouldNotReceive('createCheckout', 'retrieveCheckout', 'expireCheckout');
        $this->app->instance(StripeGateway::class, $gateway);
        $this->owner = User::factory()->create(['name' => 'María González', 'role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        $character = Character::create(['name' => 'Mickey', 'active' => true, 'outfits' => ['0']]);
        Resource::create(['code' => 'MICKEY-1', 'name' => 'Cabeza de Mickey', 'kind' => 'costume', 'status' => 'active',
            'character_id' => $character->id, 'skills' => []]);
        $variant = ServiceVariant::create(['name' => 'Música y juegos', 'status' => 'active', 'duration_minutes' => 120,
            'character_quantity' => 1, 'requirements' => [], 'character_options' => [['character_id' => $character->id,
                'requirements' => [['kind' => 'costume', 'character_id' => $character->id, 'quantity' => 1], ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 30001, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $customer = Customer::create(['name' => 'Elena Martínez', 'phone' => '346-555-0101', 'email' => 'elena@example.test', 'locale' => 'es']);
        $start = CarbonImmutable::parse('2026-11-10T00:30:00Z'); // Nov9 in Houston, never the host's date.
        $this->event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id,
            'title' => 'Cumpleaños de Lucía', 'event_type' => 'birthday', 'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->addHours(2)->toIso8601String(), 'timezone' => 'America/Chicago', 'postal_code' => '77493',
            'travel_fee_cents' => 0, 'setup_minutes' => null, 'breakdown_minutes' => null,
            'services' => [['price_version_id' => $price->id, 'characters' => [['character_id' => $character->id, 'quantity' => 1, 'outfit' => '0']]]]]);
        $this->finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => $this->event->version], (string) Str::uuid());
        $this->lease = app(PrepareQuickStripeLease::class)->execute($this->owner, $this->event->id,
            ['expected_event_version' => $this->event->version, 'financial_revision_id' => $this->finance->id], (string) Str::uuid());
    }

    protected function tearDown(): void
    {
        // Remove only disposable synthetic request evidence before DatabaseMigrations' guarded rollback.
        if (Schema::hasTable('quick_stripe_checkout_requests')) DB::table('quick_stripe_checkout_requests')->delete();
        parent::tearDown();
    }

    private function input(array $overrides = []): array
    {
        return ['expected_event_version' => $this->lease['event_version'], 'expected_lease_version' => $this->lease['version'],
            'expected_terms_sha256' => $this->lease['terms']['sha256'], ...$overrides];
    }

    private function prepare(?string $key = null, array $overrides = [], ?User $owner = null): array
    {
        return app(PrepareQuickStripeCheckoutRequest::class)->execute($owner ?? $this->owner, $this->event->id, $this->input($overrides), $key ?? (string) Str::uuid());
    }

    private function read(): array
    {
        return app(ReadQuickStripeCheckoutRequest::class)->execute($this->owner, $this->event->id);
    }

    private function additionalLease(int $totalCents, int $percent): void
    {
        $price = PriceVersion::create(['service_variant_id' => PriceVersion::firstOrFail()->service_variant_id,
            'amount_cents' => $totalCents, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = $this->event->starts_at->addDays(2);
        $this->event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $this->event->customer_id,
            'title' => 'Celebración de Elena', 'event_type' => 'birthday', 'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->addHours(2)->toIso8601String(), 'timezone' => 'America/Chicago', 'postal_code' => '77493',
            'travel_fee_cents' => 0, 'services' => [['price_version_id' => $price->id,
                'characters' => [['character_id' => Character::sole()->id, 'quantity' => 1, 'outfit' => '0']]]]]);
        $this->finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id,
            ['expected_version' => $this->event->version], (string) Str::uuid());
        $this->lease = app(PrepareQuickStripeLease::class)->execute($this->owner, $this->event->id,
            ['expected_event_version' => $this->event->version, 'financial_revision_id' => $this->finance->id, 'deposit_percent' => $percent], (string) Str::uuid());
    }

    private function conflict(callable $work): void
    {
        try { $work(); $this->fail('The stale or invalid request was accepted.'); }
        catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
    }

    public function test_durable_request_freezes_exact_principal_fee_account_origin_and_private_reference_without_payment(): void
    {
        $event = $this->event->fresh()->getRawOriginal();
        $lease = QuickStripeLease::sole()->getRawOriginal();
        $reservation = DB::table('resource_reservations')->sole();
        $v = $this->prepare();
        $r = QuickStripeCheckoutRequest::sole(); $p = $r->envelope; $body = $p['parameters'];
        $this->assertSame($v, $this->read());
        $this->assertSame('prepared_only', $v['state']);
        $this->assertFalse($v['provider_called']); $this->assertFalse($v['payment_received']); $this->assertFalse($v['method_support_verified']);
        $this->assertSame(15001, $v['principal_cents']); $this->assertSame(450, $v['customer_fee_cents']); $this->assertSame(15451, $v['payable_cents']);
        $this->assertSame('acct_syntheticWaoo', $p['account']); $this->assertSame('https://crm.example.test', $p['origin']);
        $this->assertSame('waoo-quick-test:'.$r->id, $p['provider_key']);
        $this->assertSame(['card'], $body['payment_method_types']);
        $this->assertSame($r->id, $body['client_reference_id']);
        $this->assertSame($body['metadata'], $body['payment_intent_data']['metadata']);
        $this->assertSame('quick_stripe_booking', $body['metadata']['purpose']);
        $this->assertSame('2026-11-09 · 50% · Party services / Servicios de fiesta', $body['line_items'][0]['price_data']['product_data']['description']);
        $this->assertSame('Payment fee / Cargo por pago (3%)', $body['line_items'][1]['price_data']['product_data']['name']);
        $this->assertSame($v['payable_cents'], array_sum(array_column(array_column($body['line_items'], 'price_data'), 'unit_amount')));
        $this->assertSame(now()->timestamp + 82800, $body['expires_at']);
        $this->assertSame('https://crm.example.test/portal?quick_booking_return='.$r->id, $body['success_url']);
        foreach (['Elena', 'Martínez', 'Lucía', '346-555', 'elena@', 'local_intent_id', 'local_tip_intent_id'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($p, JSON_THROW_ON_ERROR));
        }
        $this->assertArrayNotHasKey('envelope', $r->toArray());
        $this->assertArrayNotHasKey('parameters', $v); $this->assertArrayNotHasKey('provider_key', $v);
        $this->assertStringNotContainsString('waoo-quick-test:', DB::table('quick_stripe_checkout_requests')->value('envelope'));
        $audit = DB::table('audit_entries')->where('action', 'quick_stripe_request.prepared')->sole();
        $this->assertSame($this->owner->id, $audit->actor_id);
        $this->assertSame(['event_id', 'lease_id', 'lease_version', 'sha256'], array_keys(json_decode($audit->after, true)));
        $this->assertSame($event, $this->event->fresh()->getRawOriginal());
        $this->assertSame($lease, QuickStripeLease::sole()->getRawOriginal());
        $this->assertEquals($reservation, DB::table('resource_reservations')->sole());
        foreach (['ledger_entries', 'payment_intents', 'portal_grants', 'quick_bookings', 'outbound_messages', 'contracts'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public function test_full_payment_terms_are_frozen_from_the_lease_not_from_input(): void
    {
        $this->additionalLease(30001, 100);
        $v = $this->prepare();
        $this->assertSame(30001, $v['principal_cents']); $this->assertSame(900, $v['customer_fee_cents']); $this->assertSame(30901, $v['payable_cents']);
    }

    public function test_tiny_amount_with_zero_rounded_fee_keeps_the_exact_total_without_a_fake_paid_fee(): void
    {
        $this->additionalLease(1, 50);
        $v = $this->prepare();
        $this->assertSame(1, $v['principal_cents']); $this->assertSame(0, $v['customer_fee_cents']); $this->assertSame(1, $v['payable_cents']);
        $this->assertCount(1, QuickStripeCheckoutRequest::sole()->envelope['parameters']['line_items']);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_retry_after_checkout_deadline_keeps_the_exact_body_and_does_not_open_another_request(): void
    {
        $key = (string) Str::uuid(); $first = $this->prepare($key); $original = QuickStripeCheckoutRequest::sole()->getRawOriginal();
        $this->travel(24)->hours();
        $this->assertSame($first, $this->prepare($key)); $this->assertSame($first, $this->read());
        $this->assertSame($original, QuickStripeCheckoutRequest::sole()->getRawOriginal());
        $this->conflict(fn () => $this->prepare());
        $this->assertDatabaseCount('quick_stripe_checkout_requests', 1);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'quick_stripe_request.prepared')->count());
    }

    public function test_closed_input_and_idempotency_validation_cannot_override_server_parameters(): void
    {
        foreach ([['amount_cents' => 1], ['payment_method_types' => ['affirm']], ['origin' => 'https://elsewhere.example.test'],
            ['expected_terms_sha256' => strtoupper($this->lease['terms']['sha256'])], ['expected_event_version' => 0]] as $extra) {
            try { $this->prepare(overrides: $extra); $this->fail('Accepted unapproved input.'); }
            catch (\Illuminate\Validation\ValidationException) { $this->assertDatabaseCount('quick_stripe_checkout_requests', 0); }
        }
        try { $this->prepare('invalid-key'); $this->fail('Accepted a non-UUID key.'); }
        catch (\Illuminate\Validation\ValidationException) { $this->assertDatabaseCount('quick_stripe_checkout_requests', 0); }
        $key = (string) Str::uuid(); $this->prepare($key);
        $this->conflict(fn () => $this->prepare($key, ['expected_event_version' => 2]));
        $this->conflict(fn () => $this->prepare($key, ['expected_terms_sha256' => str_repeat('a', 64)]));
    }

    #[DataProvider('configurationChanges')]
    public function test_changed_server_configuration_blocks_retry_but_preserves_historical_read(string $field, mixed $value): void
    {
        $key = (string) Str::uuid(); $first = $this->prepare($key);
        config([$field => $value]);
        $this->conflict(fn () => $this->prepare($key));
        $this->assertSame($first, $this->read());
        $this->assertDatabaseCount('quick_stripe_checkout_requests', 1);
    }

    public static function configurationChanges(): array
    {
        return [['waoo.stripe_test_account', 'acct_otherSynthetic'], ['app.url', 'https://other.example.test'],
            ['waoo.quick_stripe_requested_methods', ['klarna', 'card']]];
    }

    #[DataProvider('unsupportedMethods')]
    public function test_unknown_or_ambiguous_methods_never_silently_fall_back(mixed $methods): void
    {
        config(['waoo.quick_stripe_requested_methods' => $methods]);
        try { $this->prepare(); $this->fail('Unsupported method policy accepted.'); }
        catch (RecordWriteRejected $e) { $this->assertSame(422, $e->status); }
        $this->assertDatabaseCount('quick_stripe_checkout_requests', 0);
    }

    public static function unsupportedMethods(): array
    {
        return [[['zip']], [['affirm']], [[]], [['card', 'card']], [['afterpay']], [['card', ['klarna']]], ['card'], [['method' => 'card']]];
    }

    public function test_requested_financing_is_sorted_and_never_claimed_to_be_verified_or_available(): void
    {
        config(['waoo.quick_stripe_requested_methods' => ['klarna', 'card', 'afterpay_clearpay']]);
        $v = $this->prepare();
        $this->assertSame(['afterpay_clearpay', 'card', 'klarna'], $v['requested_methods']);
        $this->assertFalse($v['method_support_verified']); $this->assertFalse($v['provider_called']);
        $this->assertArrayNotHasKey('checkout_url', $v); $this->assertArrayNotHasKey('available_methods', $v);
    }

    #[DataProvider('unsafeOrigins')]
    public function test_unsafe_provider_origins_leave_no_durable_request(string $origin): void
    {
        config(['app.url' => $origin]);
        try { $this->prepare(); $this->fail('Unsafe origin accepted.'); }
        catch (RecordWriteRejected|\Illuminate\Auth\Access\AuthorizationException) { $this->assertDatabaseCount('quick_stripe_checkout_requests', 0); }
    }

    public static function unsafeOrigins(): array
    {
        return [['https://crm.example.test/path'], ['https://crm.example.test?next=elsewhere'], ['https://user:pass@crm.example.test'],
            ['https://crm.example.test#fragment'], ['http://crm.example.test'], ["https://crm.example.test\n"]];
    }

    public function test_creation_margin_and_existing_expired_requests_do_not_renew_the_lease(): void
    {
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($this->lease['terms']['expires_at'] - 1860));
        $key = (string) Str::uuid(); $v = $this->prepare($key);
        $this->assertSame($v['checkout_expires_at'], $v['lease_expires_at']);
        $this->travel(1860)->seconds();
        $this->conflict(fn () => $this->prepare($key));
        $this->assertSame($v, $this->read());
    }

    public function test_less_than_31_minutes_remaining_cannot_create_a_new_request(): void
    {
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($this->lease['terms']['expires_at'] - 1859));
        $this->conflict(fn () => $this->prepare());
        $this->assertDatabaseCount('quick_stripe_checkout_requests', 0);
        $this->assertSame($this->lease['terms']['expires_at'], QuickStripeLease::sole()->expires_at->timestamp);
    }

    public function test_cancellation_keeps_the_original_request_for_owner_read_without_allowing_retry(): void
    {
        $key = (string) Str::uuid(); $v = $this->prepare($key);
        app(ReleaseQuickStripeLease::class)->execute($this->owner, $this->event->id, ['expected_event_version' => $this->event->version,
            'expected_lease_version' => $this->lease['version'], 'reason' => 'La dueña decidió liberar el apartado pendiente.'], (string) Str::uuid());
        $this->assertSame($v, $this->read());
        $this->conflict(fn () => $this->prepare($key));
        $this->assertDatabaseCount('resource_reservations', 0); $this->assertDatabaseCount('ledger_entries', 0);
    }

    #[DataProvider('tampering')]
    public function test_corrupt_or_rehashed_request_cannot_pass_original_audit_and_lease_evidence(string $kind): void
    {
        $key = (string) Str::uuid(); $this->prepare($key); $r = QuickStripeCheckoutRequest::sole(); $p = $r->envelope;
        if ($kind === 'ciphertext') DB::table($r->getTable())->where('id', $r->id)->update(['envelope' => 'synthetic-corruption']);
        else {
            if ($kind === 'amount') $p['parameters']['line_items'][0]['price_data']['unit_amount']++;
            if ($kind === 'origin') { $p['origin'] = 'https://other.example.test'; $p['parameters']['success_url'] = $p['origin'].'/portal?quick_booking_return='.$r->id; }
            if ($kind === 'extra') $p['ignored'] = true;
            if ($kind === 'lease') $p['lease_sha256'] = str_repeat('a', 64);
            if ($kind === 'expiry') $p['parameters']['expires_at']++;
            DB::table($r->getTable())->where('id', $r->id)->update(['envelope' => Crypt::encryptString(json_encode($p, JSON_THROW_ON_ERROR)), 'envelope_sha256' => MutationKey::hash($p)]);
        }
        $this->conflict(fn () => $this->read()); $this->conflict(fn () => $this->prepare($key));
        $this->assertDatabaseCount('quick_stripe_checkout_requests', 1); $this->assertDatabaseCount('ledger_entries', 0);
    }

    public static function tampering(): array { return array_map(fn ($v) => [$v], ['ciphertext', 'amount', 'origin', 'extra', 'lease', 'expiry']); }

    public function test_original_lease_history_and_financial_tampering_are_detected_without_touching_inventory(): void
    {
        $this->prepare(); $original = DB::table('quick_stripe_lease_events')->sole();
        DB::table('quick_stripe_lease_events')->update(['payload_sha256' => str_repeat('a', 64)]);
        $this->conflict(fn () => $this->read());
        DB::table('quick_stripe_lease_events')->update(['payload_sha256' => $original->payload_sha256]);
        DB::table('financial_revisions')->where('id', $this->finance->id)->increment('total_cents');
        $this->conflict(fn () => $this->read());
        $this->assertDatabaseCount('resource_reservations', 1); $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_reconstruction_rejects_changed_amount_even_if_both_request_and_audit_hashes_are_replaced(): void
    {
        $this->prepare(); $r = QuickStripeCheckoutRequest::sole(); $p = $r->envelope;
        $p['parameters']['line_items'][0]['price_data']['unit_amount']++;
        $hash = MutationKey::hash($p);
        DB::table($r->getTable())->where('id', $r->id)->update([
            'envelope' => Crypt::encryptString(json_encode($p, JSON_THROW_ON_ERROR)), 'envelope_sha256' => $hash]);
        $audit = DB::table('audit_entries')->where('action', 'quick_stripe_request.prepared')->sole();
        $after = json_decode($audit->after, true); $after['sha256'] = $hash;
        DB::table('audit_entries')->where('id', $audit->id)->update(['after' => json_encode($after, JSON_THROW_ON_ERROR)]);
        $this->conflict(fn () => $this->read());
        $this->assertDatabaseCount('resource_reservations', 1); $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_current_event_authority_and_payment_exception_barriers_are_not_bypassed_by_retry(): void
    {
        $key = (string) Str::uuid(); $this->prepare($key);
        DB::table('events')->where('id', $this->event->id)->update(['responsible_system' => 'honeybook']);
        try { $this->prepare($key); $this->fail('HoneyBook authority bypassed.'); }
        catch (\App\Exceptions\ResponsibilityRejected) { $this->assertDatabaseCount('quick_stripe_checkout_requests', 1); }
        DB::table('events')->where('id', $this->event->id)->update(['responsible_system' => 'crm']);
        DB::table('payment_exceptions')->insert(['event_id' => $this->event->id, 'financial_revision_id' => $this->finance->id,
            'kind' => 'stripe_reconciliation', 'exception_key' => hash('sha256', 'synthetic-open-review'), 'state' => 'open',
            'context' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->conflict(fn () => $this->prepare($key));
        $this->assertDatabaseCount('ledger_entries', 0); $this->assertDatabaseCount('resource_reservations', 1);
    }

    public function test_cancelled_historical_read_keeps_original_version_after_a_real_owner_draft_edit(): void
    {
        $v = $this->prepare();
        app(ReleaseQuickStripeLease::class)->execute($this->owner, $this->event->id, ['expected_event_version' => $this->event->version,
            'expected_lease_version' => $this->lease['version'], 'reason' => 'La dueña decidió revisar otra fecha antes de continuar.'], (string) Str::uuid());
        $event = $this->event->fresh();
        $edited = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $event->customer_id,
            'expected_version' => $event->version, 'title' => 'Celebración de Elena', 'event_type' => 'birthday',
            'starts_at' => $event->starts_at->addDay()->toIso8601String(), 'ends_at' => $event->ends_at->addDay()->toIso8601String(),
            'timezone' => 'America/Chicago', 'postal_code' => '77493', 'travel_fee_cents' => 0,
            'services' => [['price_version_id' => PriceVersion::sole()->id,
                'characters' => [['character_id' => Character::sole()->id, 'quantity' => 1, 'outfit' => '0']]]]], $event->id);
        $this->assertGreaterThan($v['event_version'], $edited->version);
        $this->assertSame($v, $this->read());
        $this->assertDatabaseCount('quick_stripe_checkout_requests', 1); $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_staging_requires_secure_transport_before_prepare_or_read(): void
    {
        $this->prepare();
        $this->app->instance('env', 'staging'); config(['session.secure' => false]);
        foreach ([fn () => $this->prepare(), fn () => $this->read()] as $work) {
            try { $work(); $this->fail('Insecure staging operation accepted.'); }
            catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertDatabaseCount('quick_stripe_checkout_requests', 1); }
        }
    }

    public function test_missing_account_configuration_and_stale_source_pins_leave_the_lease_intact(): void
    {
        config(['waoo.stripe_test_account' => null]);
        $this->conflict(fn () => $this->prepare());
        config(['waoo.stripe_test_account' => 'acct_syntheticWaoo']);
        $this->conflict(fn () => $this->prepare(overrides: ['expected_event_version' => 2]));
        $this->conflict(fn () => $this->prepare(overrides: ['expected_lease_version' => 2]));
        $this->conflict(fn () => $this->prepare(overrides: ['expected_terms_sha256' => str_repeat('a', 64)]));
        $this->assertDatabaseCount('quick_stripe_checkout_requests', 0);
        $this->assertDatabaseCount('resource_reservations', 1); $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_audit_failure_rolls_back_request_and_mutation_receipt_while_preserving_the_original_lease(): void
    {
        $key = (string) Str::uuid(); $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && str_starts_with(strtolower($query->sql), 'insert into ') && str_contains($query->sql, 'audit_entries')
                && in_array('quick_stripe_request.prepared', $query->bindings, true)) throw new \RuntimeException('Synthetic request audit failure');
        });
        $count = DB::table('mutation_receipts')->count();
        try { $this->prepare($key); $this->fail('Audit failure committed.'); }
        catch (\RuntimeException $e) { $this->assertSame('Synthetic request audit failure', $e->getMessage()); }
        $this->assertDatabaseCount('quick_stripe_checkout_requests', 0); $this->assertDatabaseCount('mutation_receipts', $count);
        $this->assertDatabaseCount('resource_reservations', 1); $this->assertDatabaseCount('quick_stripe_lease_events', 1);
        $fail = false; $this->prepare($key);
        $this->assertDatabaseCount('quick_stripe_checkout_requests', 1);
    }

    #[DataProvider('deniedActors')]
    public function test_authority_is_rechecked_for_prepare_and_read(string $kind): void
    {
        $key = (string) Str::uuid(); $this->prepare($key);
        if ($kind === 'staff') $this->owner->forceFill(['role' => 'staff'])->save();
        if ($kind === 'unenrolled') $this->owner->forceFill(['two_factor_confirmed_at' => null])->save();
        if ($kind === 'inactive') $this->owner->forceFill(['active' => false])->save();
        if ($kind === 'feature') config(['waoo.sandbox_quick_stripe' => false]);
        if ($kind === 'documents') config(['waoo.sandbox_documents' => false]);
        if ($kind === 'finance') config(['waoo.sandbox_finance' => false]);
        foreach ([fn () => $this->prepare($key), fn () => $this->read()] as $work) {
            try { $work(); $this->fail('Unauthorized actor accepted.'); }
            catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertDatabaseCount('quick_stripe_checkout_requests', 1); }
        }
    }

    public static function deniedActors(): array { return array_map(fn ($v) => [$v], ['staff', 'unenrolled', 'inactive', 'feature', 'documents', 'finance']); }

    public function test_two_owners_cannot_prepare_two_requests_for_one_lease(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') $this->markTestSkipped('Requires real MariaDB row locks.');
        $other = User::factory()->create(['name' => 'Sofía Pérez', 'role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $event = $this->event->id; $input = $this->input();
        $workers = [];
        foreach ([$this->owner->id, $other->id] as $owner) {
            $workers[] = static function () use ($owner, $event, $input) {
                try { app(PrepareQuickStripeCheckoutRequest::class)->execute(User::findOrFail($owner), $event, $input, (string) Str::uuid()); return 'prepared'; }
                catch (RecordWriteRejected $e) { return 'blocked:'.$e->status; }
            };
        }
        $results = ConcurrentWorkers::run($workers); sort($results);
        $this->assertSame(['blocked:409', 'prepared'], $results);
        $this->assertDatabaseCount('quick_stripe_checkout_requests', 1);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'quick_stripe_request.prepared')->count());
        $this->assertDatabaseCount('resource_reservations', 1); $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_mariadb_migration_roundtrip_is_empty_only_and_models_preserve_history(): void
    {
        $migration = require database_path('migrations/2026_10_20_580000_create_quick_stripe_checkout_requests.php');
        $migration->down(); $this->assertFalse(Schema::hasTable('quick_stripe_checkout_requests'));
        $migration->up(); $this->prepare();
        try { $migration->down(); $this->fail('Dropped durable request evidence.'); }
        catch (\LogicException) { $this->assertDatabaseCount('quick_stripe_checkout_requests', 1); }
        $r = QuickStripeCheckoutRequest::sole();
        try { $r->delete(); $this->fail('Deleted immutable request.'); }
        catch (\LogicException) { $this->assertDatabaseCount('quick_stripe_checkout_requests', 1); }
        try { $r->update(['envelope_sha256' => str_repeat('a', 64)]); $this->fail('Updated immutable request.'); }
        catch (\LogicException) { $this->assertSame($r->getRawOriginal('envelope_sha256'), $r->fresh()->envelope_sha256); }
    }
}
