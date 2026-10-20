<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Character, Customer, Event, PriceVersion, Quote, QuoteRevision, ServiceVariant, User};
use App\Services\Quotes\{CreateQuote, ExpireQuotes, ReadQuotes, ReviseQuote};
use App\Services\Records\SaveEventDraft;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class QuoteFoundationTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private Event $event;
    private PriceVersion $price;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_quotes' => true, 'waoo.sandbox_finance' => true]);
        $this->owner = $this->owner(); $this->actingAs($this->owner);
        $customer = Customer::create(['name' => 'Fixture Quote Client', 'email' => 'fixture@example.test', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fixture Quote Package', 'long_description' => 'Fictional package for automated verification.', 'status' => 'active',
            'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $this->price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10001, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = now()->addDays(20)->startOfHour();
        $this->event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id, 'title' => 'Fixture quote party',
            'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago',
            'address' => '123 Fictional Venue, TX 77433', 'postal_code' => '77433', 'travel_fee_cents' => 2500, 'services' => [['price_version_id' => $this->price->id]]]);
    }
    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
    }
    private function input(array $over = []): array { return [...['event_id' => $this->event->id, 'expected_event_version' => $this->event->version], ...$over]; }
    private function create(array $over = [], ?string $key = null): QuoteRevision
    {
        return app(CreateQuote::class)->execute($this->owner, $this->input($over), $key ?? (string) Str::uuid());
    }
    private function revisionInput(array $over = []): array { return [...['expected_version' => 1, 'expected_event_version' => $this->event->fresh()->version, 'discounts' => []], ...$over]; }

    public function test_quote_totals_odd_cent_discount_and_approval_are_server_derived_without_booking_side_effects(): void
    {
        $r = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/admin/api/quotes', $this->input([
            'owner_note' => 'Private owner-only note', 'discounts' => [['label' => 'Explicit fixture adjustment', 'amount_cents' => 1000, 'reason' => 'Approved adjustment for this fictional event.']],
        ]))->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('revision');
        $this->assertSame([10001,2500,1000,11501,5751], array_map(fn ($k) => $r[$k], ['subtotal_cents','travel_fee_cents','discount_cents','total_cents','deposit_target_cents']));
        $this->assertSame($this->owner->id, $r['discounts_snapshot'][0]['approved_by']);
        $this->assertSame(1, $r['lines_snapshot'][0]['quantity']);
        $this->assertMatchesRegularExpression('/^Q-\d{4}-\d{6}$/', Quote::sole()->number);
        $this->assertSame(1, $this->event->fresh()->version);
        foreach (['financial_revisions','ledger_entries','resource_reservations','portal_grants','quick_bookings','contracts'] as $table) $this->assertDatabaseCount($table, 0);
        $this->assertSame('draft', $this->event->fresh()->status);
    }
    public function test_quote_revisions_freeze_names_prices_and_previous_history_after_catalog_and_draft_change(): void
    {
        $r1 = $this->create(); $original = $r1->getAttributes();
        $variant = ServiceVariant::findOrFail($this->price->service_variant_id); $variant->forceFill(['name' => 'New future package label'])->save();
        $price2 = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 20001, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $this->event->services()->first()->update(['price_version_id' => $price2->id]);
        $this->event->forceFill(['version' => 2])->save();
        $r2 = app(ReviseQuote::class)->execute($this->owner, $r1->quote_id, $this->revisionInput(), (string) Str::uuid());
        $this->assertSame(2, $r2->revision); $this->assertSame(22501, $r2->total_cents);
        $this->assertSame('New future package label', $r2->lines_snapshot[0]['name']);
        $stored = $r1->fresh()->getAttributes(); ksort($original); ksort($stored);
        $this->assertSame($original, $stored);
        $this->assertSame('Fixture Quote Package', $r1->fresh()->lines_snapshot[0]['name']);
        $this->assertSame(2, Quote::sole()->version);
        $this->expectException(\LogicException::class); $r1->update(['total_cents' => 1]);
    }
    public function test_create_retry_returns_original_revision_after_revise_catalog_change_and_original_expiry(): void
    {
        $key = (string) Str::uuid(); $r = $this->create(key: $key);
        app(ReviseQuote::class)->execute($this->owner, $r->quote_id, $this->revisionInput(), (string) Str::uuid());
        ServiceVariant::whereKey($this->price->service_variant_id)->update(['status' => 'retired']);
        $this->travel(8)->days();
        $same = $this->create(key: $key);
        $this->assertSame($r->id, $same->id); $this->assertSame(1, $same->revision);
        $this->assertDatabaseCount('quotes', 1); $this->assertDatabaseCount('quote_revisions', 2);
        $this->assertSame(1, DB::table('audit_entries')->where('action','quote.created')->count());
        $this->withHeader('Idempotency-Key', $key)->postJson('/admin/api/quotes', $this->input(['locale' => 'en']))->assertConflict();
    }
    public function test_revision_retry_returns_the_original_result_after_later_revision(): void
    {
        $r = $this->create(); $key = (string) Str::uuid(); $data = $this->revisionInput();
        $second = app(ReviseQuote::class)->execute($this->owner, $r->quote_id, $data, $key);
        app(ReviseQuote::class)->execute($this->owner, $r->quote_id, $this->revisionInput(['expected_version' => 2]), (string) Str::uuid());
        $this->assertSame($second->id, app(ReviseQuote::class)->execute($this->owner, $r->quote_id, $data, $key)->id);
        $this->assertDatabaseCount('quote_revisions', 3);
    }
    public function test_stale_event_and_quote_versions_reject_without_new_evidence(): void
    {
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/admin/api/quotes', $this->input(['expected_event_version' => 9]))->assertConflict();
        $this->assertDatabaseCount('quotes', 0);
        $r = $this->create();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/admin/api/quotes/{$r->quote_id}/revisions", $this->revisionInput(['expected_version' => 9]))->assertConflict();
        $this->event->forceFill(['version' => 2])->save();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/admin/api/quotes/{$r->quote_id}/revisions", $this->revisionInput(['expected_event_version' => 1]))->assertConflict();
        $this->assertDatabaseCount('quote_revisions', 1); $this->assertSame(1, Quote::sole()->version);
    }
    public function test_discount_overflow_approval_spoof_and_expiry_reject_before_any_quote_write(): void
    {
        $discount = ['label' => 'Fixture discount', 'amount_cents' => 1, 'reason' => 'This is an explicit fixture adjustment.'];
        foreach ([['total_cents' => 1], ['discounts' => [[...$discount, 'approved_by' => 999]]],
            ['discounts' => [[...$discount,'amount_cents' => 12501]]], ['discounts' => [[...$discount,'amount_cents' => -1]]],
            ['discounts' => [[...$discount,'expires_at' => now()->addDay()->toIso8601String()]]],
            ['valid_until' => now()->subDay()->toIso8601String()], ['valid_until' => now()->addDays(30)->toIso8601String()],
            ['valid_until' => '2026-12-01 12:00:00']] as $over) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/admin/api/quotes', $this->input($over))->assertUnprocessable();
        }
        $this->assertDatabaseCount('quotes', 0); $this->assertDatabaseCount('quote_revisions', 0);
    }
    public function test_revise_requires_explicit_discount_list_and_can_clear_it_without_touching_old_revision(): void
    {
        $r = $this->create(['discounts' => [['label' => 'Fixture discount', 'amount_cents' => 1000, 'reason' => 'Explicit fixture adjustment reason.']]]);
        $data = $this->revisionInput(); unset($data['discounts']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/admin/api/quotes/{$r->quote_id}/revisions", $data)->assertUnprocessable()->assertJsonValidationErrors('discounts');
        $second = app(ReviseQuote::class)->execute($this->owner, $r->quote_id, $this->revisionInput(), (string) Str::uuid());
        $this->assertSame(12501, $second->total_cents); $this->assertSame([], $second->discounts_snapshot);
        $this->assertSame(11501, $r->fresh()->total_cents);
    }
    public function test_missing_travel_incomplete_characters_or_inactive_catalog_cannot_be_quoted(): void
    {
        $this->event->update(['travel_fee_cents' => null]);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/admin/api/quotes', $this->input())->assertUnprocessable();
        $this->event->update(['travel_fee_cents' => 2500]);
        $service = $this->event->services()->first(); $service->update(['operational_ready' => false]);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/admin/api/quotes', $this->input())->assertUnprocessable();
        $service->update(['operational_ready' => true]); ServiceVariant::whereKey($this->price->service_variant_id)->update(['status' => 'draft']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/admin/api/quotes', $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('quotes', 0);
    }
    public function test_character_outfit_is_frozen_but_private_character_note_is_excluded(): void
    {
        $character = Character::create(['name' => 'Fixture Character', 'active' => true, 'outfits' => ['0']]);
        $this->event->services()->first()->characters()->create(['character_id' => $character->id, 'quantity' => 1,
            'character_name_snapshot' => $character->name, 'outfit' => '0', 'note' => 'PRIVATE_CHARACTER_NOTE']);
        $r = $this->create(); $this->assertSame('0', $r->lines_snapshot[0]['characters'][0]['outfit']);
        $this->assertStringNotContainsString('PRIVATE_CHARACTER_NOTE', $r->toJson());
    }
    public function test_late_audit_failure_rolls_back_new_quote_revision_and_receipt(): void
    {
        $fail = true;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$fail) {
            if ($fail && str_contains($sql, 'audit_entries') && in_array('quote.created', $bindings, true)) throw new \RuntimeException('Fixture quote audit rejection');
        });
        try { $this->create(); $this->fail('Expected audit failure'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture quote audit rejection', $e->getMessage()); }
        finally { $fail = false; }
        $this->assertDatabaseCount('quotes', 0); $this->assertDatabaseCount('quote_revisions', 0); $this->assertDatabaseCount('mutation_receipts', 0);
    }
    public function test_revision_audit_failure_preserves_original_header_and_history(): void
    {
        $r = $this->create(); $fail = true;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$fail) {
            if ($fail && str_contains($sql, 'audit_entries') && in_array('quote.revised', $bindings, true)) throw new \RuntimeException('Fixture revise rejection');
        });
        try { app(ReviseQuote::class)->execute($this->owner, $r->quote_id, $this->revisionInput(), (string) Str::uuid()); $this->fail('Expected audit failure'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture revise rejection', $e->getMessage()); }
        finally { $fail = false; }
        $this->assertSame(1, Quote::sole()->version); $this->assertDatabaseCount('quote_revisions', 1); $this->assertDatabaseCount('mutation_receipts', 1);
    }
    public function test_expiry_is_bounded_idempotent_and_never_expires_accepted_quotes(): void
    {
        $r = $this->create(); $this->travel(8)->days();
        $this->getJson('/admin/api/quotes?status=expired')->assertOk()->assertJsonPath('data.0.effective_status', 'expired');
        $this->getJson('/admin/api/quotes?status=draft')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, app(ExpireQuotes::class)->execute(1)); $this->assertSame(0, app(ExpireQuotes::class)->execute(1));
        $this->assertSame(2, Quote::sole()->version);
        $this->assertSame(1, DB::table('audit_entries')->where('action','quote.expired')->whereNull('actor_id')->count());
        Quote::whereKey($r->quote_id)->update(['status' => 'accepted']);
        $this->assertSame(0, app(ExpireQuotes::class)->execute());
        $this->assertSame('accepted', app(ReadQuotes::class)->show($this->owner, $r->quote_id)['quote']['effective_status']);
        $this->artisan('waoo:expire-quotes', ['--limit' => '501'])->assertFailed();
        $this->artisan('waoo:expire-quotes', ['--limit' => '2'])->expectsOutput('Expired: 0')->assertSuccessful();
    }
    public function test_read_is_bounded_private_and_planned_client_or_send_paths_remain_unavailable(): void
    {
        $r = $this->create(['owner_note' => 'Private fixture note']);
        $this->getJson('/admin/api/quotes?per_page=1&page=1')->assertOk()->assertJsonPath('total', 1)->assertHeader('Cache-Control','no-store, private');
        $this->getJson('/admin/api/quotes?per_page=1&page=2')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/admin/api/quotes?per_page=51')->assertUnprocessable();
        $this->getJson('/admin/api/quotes/'.$r->quote_id)->assertOk()->assertJsonPath('quote.owner_note','Private fixture note')->assertJsonCount(1,'revisions');
        config(['waoo.sandbox_documents'=>true, 'waoo.quote_acceptance_consent'=>[]]); // still refused while no consent wording is configured
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/admin/api/quotes/'.$r->quote_id.'/send', ['expected_version'=>1,'expected_revision'=>1])->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->getJson('/portal/api/quote')->assertForbidden();
        $this->assertDatabaseCount('portal_grants', 0);
    }
    public function test_direct_and_http_gates_reauthorize_stale_actor_and_default_off_feature(): void
    {
        config(['waoo.sandbox_quotes' => false]);
        $this->getJson('/admin/api/quotes')->assertForbidden();
        config(['waoo.sandbox_quotes' => true]);
        User::whereKey($this->owner->id)->update(['active' => false]);
        $this->getJson('/admin/api/quotes')->assertForbidden();
        try { $this->create(); $this->fail('Expected fresh actor denial'); } catch (AuthorizationException) {}
        $this->assertDatabaseCount('quotes',0);
    }
    public function test_http_enforces_two_factor_production_and_actual_staging_csrf(): void
    {
        User::whereKey($this->owner->id)->update(['two_factor_confirmed_at' => null]); $this->actingAs($this->owner->fresh());
        $this->getJson('/admin/api/quotes')->assertForbidden();
        User::whereKey($this->owner->id)->update(['two_factor_confirmed_at' => now()]); $this->actingAs($this->owner->fresh());
        $this->app->detectEnvironment(fn () => 'production');
        $this->getJson('/admin/api/quotes')->assertForbidden();
        $this->app->detectEnvironment(fn () => 'staging');
        $this->postJson('/admin/api/quotes', $this->input())->assertStatus(419)->assertHeader('Cache-Control','no-store, private');
        $this->app->detectEnvironment(fn () => 'testing');
    }
    public function test_two_actual_owners_cannot_create_two_quotes_for_one_event(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires MariaDB and pcntl');
        $other = $this->owner(); $data = $this->input();
        $worker = fn ($actor) => function () use ($actor,$data) {
            try { return app(CreateQuote::class)->execute($actor,$data,(string) Str::uuid())->id; }
            catch (RecordWriteRejected $e) { return $e->status; }
        };
        $results = ConcurrentWorkers::run([$worker($this->owner), $worker($other)]);
        $this->assertContains(409, $results); $this->assertContains(QuoteRevision::sole()->id, $results);
        $this->assertDatabaseCount('quotes',1); $this->assertDatabaseCount('quote_revisions',1);
    }
    public function test_two_actual_identical_retries_create_one_receipt_and_revision(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires MariaDB and pcntl');
        $data = $this->input(); $key = (string) Str::uuid();
        $worker = fn () => app(CreateQuote::class)->execute($this->owner,$data,$key)->id;
        $results = ConcurrentWorkers::run([$worker,$worker]);
        $this->assertSame($results[0],$results[1]); $this->assertIsInt($results[0]);
        $this->assertDatabaseCount('quotes',1); $this->assertDatabaseCount('quote_revisions',1); $this->assertDatabaseCount('mutation_receipts',1);
    }
    public function test_two_actual_owners_revising_one_displayed_version_cannot_overwrite_each_other(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires MariaDB and pcntl');
        $r = $this->create(); $other = $this->owner(); $data = $this->revisionInput();
        $worker = fn ($actor) => function () use ($actor,$r,$data) {
            try { return app(ReviseQuote::class)->execute($actor,$r->quote_id,$data,(string) Str::uuid())->id; }
            catch (RecordWriteRejected $e) { return $e->status; }
        };
        $results = ConcurrentWorkers::run([$worker($this->owner),$worker($other)]);
        $this->assertContains(409,$results); $this->assertSame(2,Quote::sole()->version); $this->assertDatabaseCount('quote_revisions',2);
    }
}
