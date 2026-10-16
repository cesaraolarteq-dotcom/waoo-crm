<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, EventCostSnapshot, FinancialRevision, PriceVersion, ServiceVariant, User};
use App\Services\Costs\{EventMarginSummary, RecordCostSnapshot};
use App\Services\Finance\{CreateFinancialRevision, RecordZellePayment};
use App\Services\Portal\IssuePortalGrant;
use App\Services\Records\SaveEventDraft;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue};
use Illuminate\Support\Str;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class EventCostSnapshotsTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner; private Event $event; private FinancialRevision $finance; private array $input;
    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]); Queue::fake();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]); $this->actingAs($this->owner);
        $customer = Customer::create(['name' => 'Fixture Client', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fixture package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $this->owner->id]); $start = now()->addDays(20)->startOfHour();
        $this->event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id, 'title' => 'Fixture party', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => 5500, 'services' => [['price_version_id' => $price->id]]]);
        $this->finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        $this->input = ['kind' => 'estimate', 'expected_event_version' => 1, 'financial_revision_id' => $this->finance->id, 'expected_revision' => 0,
            'coverage_confirmed' => true, 'note' => 'Fixture owner entered all direct costs.', 'lines' => [
                ['label' => 'Fixture staff pay', 'category' => 'staff_labor', 'amount_cents' => 12000, 'event_service_id' => $this->finance->lines_snapshot[0]['event_service_id']],
                ['label' => 'Fixture staff transport', 'category' => 'staff_transport', 'amount_cents' => 3500],
            ]];
    }
    private function store(array $extra = [], ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/admin/api/events/'.$this->event->id.'/cost-snapshots', [...$this->input, ...$extra]);
    }
    private function summary() { return $this->getJson('/admin/api/events/'.$this->event->id.'/costs'); }
    public function test_event_without_agreement_has_no_invented_revenue_cost_or_margin(): void
    {
        $other = $this->event->replicate(); $other->save();
        $summary = app(EventMarginSummary::class)->forEvent($this->owner, $other->id);
        $this->assertFalse($summary['agreement_current']);
        foreach (['financial_revision_id','revenue_cents','customer_balance_cents','estimate','actual'] as $field) $this->assertNull($summary[$field]);
        $this->assertSame(0, $summary['cash_received_cents']);
    }
    public function test_unknown_cost_and_missing_coverage_never_report_complete_margin(): void
    {
        $this->store(['lines' => [...$this->input['lines'], ['label' => 'Fixture unknown materials', 'category' => 'materials', 'amount_cents' => null]]])->assertCreated()->assertJsonPath('complete', false);
        $this->summary()->assertOk()->assertJsonPath('estimate.known_cost_cents', 15500)->assertJsonPath('estimate.unknown_line_count', 1)
            ->assertJsonPath('estimate.total_cost_cents', null)->assertJsonPath('estimate.contribution_margin_cents', null);
        $this->store(['expected_revision' => 1, 'coverage_confirmed' => false])->assertCreated();
        $this->summary()->assertOk()->assertJsonPath('estimate.known_cost_cents', 15500)->assertJsonPath('estimate.complete', false)->assertJsonPath('estimate.contribution_margin_cents', null);
    }
    public function test_estimate_and_actual_remain_separate_and_transport_is_not_subtracted_from_customer_fee(): void
    {
        $this->store()->assertCreated();
        $this->store(['kind' => 'actual', 'lines' => [
            ['label' => 'Fixture actual pay', 'category' => 'staff_labor', 'amount_cents' => 15000],
            ['label' => 'Fixture actual transport', 'category' => 'staff_transport', 'amount_cents' => 3500],
            ['label' => 'Fixture no materials expense', 'category' => 'materials', 'amount_cents' => 0],
        ]])->assertCreated();
        $this->summary()->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('revenue_cents', 45500)
            ->assertJsonPath('estimate.contribution_margin_cents', 30000)->assertJsonPath('actual.contribution_margin_cents', 27000)
            ->assertJsonPath('actual.total_cost_cents', 18500)->assertJsonPath('cash_received_cents', 0)->assertJsonPath('customer_balance_cents', 45500);
        $this->assertDatabaseCount('ledger_entries', 0); $this->assertDatabaseCount('resource_reservations', 0);
        $this->assertSame(\App\Services\Finance\MutationKey::hash($this->finance->getAttributes()), \App\Services\Finance\MutationKey::hash($this->finance->fresh()->getAttributes()));
    }
    public function test_zero_cost_needs_explicit_coverage_and_negative_margin_is_not_clamped(): void
    {
        $this->store(['lines' => [], 'coverage_confirmed' => false])->assertCreated();
        $this->summary()->assertJsonPath('estimate.contribution_margin_cents', null);
        $this->store(['lines' => [], 'expected_revision' => 1])->assertCreated();
        $this->summary()->assertJsonPath('estimate.total_cost_cents', 0)->assertJsonPath('estimate.contribution_margin_cents', 45500);
        $this->store(['kind' => 'actual', 'lines' => [['label' => 'Fixture high cost', 'category' => 'other', 'amount_cents' => 60000]]])->assertCreated();
        app(RecordZellePayment::class)->execute($this->owner, $this->event->id, ['financial_revision_id' => $this->finance->id, 'amount_cents' => 10000, 'currency' => 'USD',
            'reference' => 'FIXTURE-COST-CASH', 'received_at' => now()->subHour()->toIso8601String(), 'verified_received' => true], (string) Str::uuid());
        $this->summary()->assertJsonPath('actual.contribution_margin_cents', -14500)->assertJsonPath('cash_received_cents', 10000)->assertJsonPath('customer_balance_cents', 35500);
    }
    public function test_history_and_idempotency_preserve_prior_snapshots_and_prevent_stale_editor_overwrite(): void
    {
        $key = (string) Str::uuid(); $first = $this->store([], $key)->assertCreated()->json('id');
        $this->store(['expected_event_version' => '1', 'expected_revision' => '0'], $key)->assertCreated()->assertJsonPath('id', $first);
        $this->store()->assertConflict();
        $this->store(['expected_revision' => 1, 'note' => 'Fixture owner corrected transport.'])->assertCreated()->assertJsonPath('revision', 2);
        $this->store([], $key)->assertCreated()->assertJsonPath('id', $first);
        $this->store(['note' => 'Fixture altered request payload.'], $key)->assertConflict();
        $this->getJson('/admin/api/events/'.$this->event->id.'/cost-snapshots?kind=estimate')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.revision', 2)->assertJsonPath('data.1.id', $first);
        $this->assertSame('Fixture owner entered all direct costs.', EventCostSnapshot::findOrFail($first)->note);
        $this->assertSame(2, DB::table('audit_entries')->where('action', 'cost.snapshot_recorded')->count());
    }
    public function test_financial_and_event_changes_mark_margin_stale_instead_of_reusing_old_costs(): void
    {
        $this->store()->assertCreated();
        $latest = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        $this->summary()->assertJsonPath('estimate.current', false)->assertJsonPath('estimate.contribution_margin_cents', null);
        $this->store(['expected_revision' => 1])->assertConflict();
        $this->store(['expected_revision' => 1, 'financial_revision_id' => $latest->id])->assertCreated();
        $this->summary()->assertJsonPath('estimate.current', true)->assertJsonPath('estimate.contribution_margin_cents', 30000);
        DB::table('events')->where('id', $this->event->id)->increment('version');
        $this->store(['expected_revision' => 2, 'financial_revision_id' => $latest->id])->assertConflict();
        $this->summary()->assertJsonPath('agreement_current', false)->assertJsonPath('revenue_cents', null)->assertJsonPath('customer_balance_cents', null)->assertJsonPath('estimate.current', false)->assertJsonPath('estimate.contribution_margin_cents', null);
    }
    public function test_invalid_costs_missing_coverage_and_foreign_service_cannot_be_saved(): void
    {
        $this->store(['coverage_confirmed' => null])->assertUnprocessable();
        $this->store(['lines' => [['label' => 'Fixture incomplete amount', 'category' => 'staff_labor']]])->assertUnprocessable();
        $this->store(['lines' => [['label' => 'Fixture negative amount', 'category' => 'materials', 'amount_cents' => -1]]])->assertUnprocessable();
        $this->store(['lines' => [['label' => 'Fixture unknown category', 'category' => 'invented', 'amount_cents' => 0]]])->assertUnprocessable();
        $this->store(['lines' => [['label' => 'Fixture wrong service', 'category' => 'materials', 'amount_cents' => 100, 'event_service_id' => 999999]]])->assertUnprocessable();
        $this->assertDatabaseCount('event_cost_snapshots', 0);
        $this->getJson('/admin/api/events/'.$this->event->id.'/cost-snapshots?per_page=999')->assertUnprocessable();
    }
    public function test_owner_permissions_are_checked_for_direct_services_and_http_and_costs_stay_out_of_portal(): void
    {
        $this->store(['note' => 'PRIVATE-COST-FIXTURE-NOTE'])->assertCreated();
        $this->owner->forceFill(['active' => false])->save();
        try { app(EventMarginSummary::class)->forEvent($this->owner, $this->event->id); $this->fail('Inactive owner read costs.'); }
        catch (\Illuminate\Auth\Access\AuthorizationException $e) { $this->assertStringContainsString('administración', $e->getMessage()); }
        $this->store()->assertForbidden(); $this->summary()->assertForbidden();
        $this->owner->forceFill(['active' => true, 'role' => 'staff'])->save(); $this->summary()->assertForbidden();
        $this->owner->forceFill(['role' => 'owner_admin'])->save();
        $grant = app(IssuePortalGrant::class)->execute($this->owner, $this->event->id, ['customer_id' => $this->event->customer_id, 'expires_at' => now()->addDay()->toIso8601String()]);
        auth()->logout(); $this->app['auth']->forgetGuards(); $this->postJson('/portal/api/session', ['token' => $grant->token])->assertOk();
        $this->getJson('/portal/api/event')->assertOk()->assertDontSee('PRIVATE-COST-FIXTURE-NOTE')->assertDontSee('Fixture staff pay')->assertJsonMissingPath('costs');
        $this->summary()->assertUnauthorized();
    }
    public function test_disabled_sandbox_and_unconfirmed_2fa_cannot_record_or_read_costs(): void
    {
        config(['waoo.sandbox_finance' => false]); $this->store()->assertForbidden(); $this->summary()->assertForbidden();
        config(['waoo.sandbox_finance' => true]); $this->owner->forceFill(['two_factor_confirmed_at' => null])->save();
        try { app(RecordCostSnapshot::class)->execute($this->owner, $this->event->id, $this->input, (string) Str::uuid()); $this->fail('Unconfirmed actor accepted.'); }
        catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertDatabaseCount('event_cost_snapshots', 0); }
        $this->summary()->assertForbidden();
    }
    public function test_failed_audit_rolls_back_snapshot_and_key(): void
    {
        $count = DB::table('mutation_receipts')->count(); $fail = true;
        DB::connection()->beforeExecuting(function ($query, $bindings) use (&$fail) {
            if ($fail && str_contains($query, 'audit_entries') && in_array('cost.snapshot_recorded', $bindings, true)) throw new \RuntimeException('Fixture audit failure');
        });
        try { app(RecordCostSnapshot::class)->execute($this->owner, $this->event->id, $this->input, (string) Str::uuid()); $this->fail('Audit failure ignored.'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture audit failure', $e->getMessage()); }
        finally { $fail = false; }
        $this->assertDatabaseCount('event_cost_snapshots', 0); $this->assertDatabaseCount('mutation_receipts', $count);
    }
    public function test_two_editors_with_same_displayed_revision_cannot_replace_each_other(): void
    {
        $actor = $this->owner; $eventId = $this->event->id; $input = $this->input;
        $worker = function () use ($actor, $eventId, $input) {
            try { return app(RecordCostSnapshot::class)->execute($actor, $eventId, $input, (string) Str::uuid())->id; }
            catch (\App\Exceptions\RecordWriteRejected $e) { return $e->status === 409 ? 'conflict' : 'unexpected'; }
        };
        $results = ConcurrentWorkers::run([$worker, $worker]);
        $this->assertContains('conflict', $results); $this->assertContains(EventCostSnapshot::sole()->id, $results); $this->assertDatabaseCount('event_cost_snapshots', 1);
    }
    public function test_concurrent_retry_keeps_one_cost_snapshot(): void
    {
        $actor = $this->owner; $eventId = $this->event->id; $input = $this->input; $key = (string) Str::uuid();
        $worker = fn () => app(RecordCostSnapshot::class)->execute($actor, $eventId, $input, $key)->id;
        $results = ConcurrentWorkers::run([$worker, $worker]);
        $this->assertIsInt($results[0]); $this->assertSame($results[0], $results[1]); $this->assertDatabaseCount('event_cost_snapshots', 1);
    }
}
