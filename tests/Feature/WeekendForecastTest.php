<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, PriceVersion, ServiceVariant, User};
use App\Services\Costs\RecordCostSnapshot;
use App\Services\Finance\CreateFinancialRevision;
use App\Services\Records\SaveEventDraft;
use App\Services\Reports\WeekendForecast;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class WeekendForecastTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner; private Event $event; private FinancialRevision $finance; private array $input;
    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]); Queue::fake();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]); $this->actingAs($this->owner);
        $customer = Customer::create(['name' => 'Fixture Client', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fixture package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $this->owner->id]); $start = \Carbon\CarbonImmutable::parse('2026-11-07 15:00:00', 'America/Chicago');
        $this->event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id, 'title' => 'Fixture party', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => 5500, 'services' => [['price_version_id' => $price->id]]]);
        $this->finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        $this->input = ['kind' => 'estimate', 'expected_event_version' => 1, 'financial_revision_id' => $this->finance->id, 'expected_revision' => 0,
            'coverage_confirmed' => true, 'note' => 'Fixture owner entered all direct costs.', 'lines' => [
                ['label' => 'Fixture staff pay', 'category' => 'staff_labor', 'amount_cents' => 12000, 'event_service_id' => $this->finance->lines_snapshot[0]['event_service_id']],
                ['label' => 'Fixture staff transport', 'category' => 'staff_transport', 'amount_cents' => 3500],
            ]];
    }
    private function forecast(string $anchor = '2026-11-02')
    {
        return $this->getJson('/admin/api/reports/weekend?anchor='.$anchor);
    }

    private function reserve(): void
    {
        // Report fixture only: actual capacity/payment concurrency remains covered by core tests.
        DB::table('events')->where('id', $this->event->id)->update(['status' => 'capacity_reserved']);
        $this->event->refresh();
    }

    public function test_only_confirmed_weekend_starts_count_and_drafts_stay_separate(): void
    {
        $this->forecast()->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('event_count', 0)
            ->assertJsonPath('draft_event_count', 1)->assertJsonPath('estimate.staff_cost_cents', 0);
        $this->reserve();
        $other = $this->event->replicate(); $other->forceFill(['status' => 'draft'])->save();
        $this->forecast()->assertJsonPath('event_count', 1)->assertJsonPath('draft_event_count', 1)
            ->assertJsonPath('estimate.staff_cost_cents', null)->assertJsonPath('estimate.pending_events', 1)
            ->assertJsonPath('pending_balance_cents', 45500)->assertDontSee('Fixture Client')->assertDontSee('cash');
        DB::table('events')->where('id', $this->event->id)->update(['status' => 'cancelled']);
        $this->forecast()->assertJsonPath('event_count', 0)->assertJsonPath('draft_event_count', 1);
    }

    public function test_weekend_boundaries_exclude_friday_and_next_monday(): void
    {
        $this->reserve();
        foreach (['2026-11-07 05:59:59' => 0, '2026-11-07 06:00:00' => 1, '2026-11-09 05:59:59' => 1, '2026-11-09 06:00:00' => 0] as $at => $count) {
            DB::table('events')->where('id', $this->event->id)->update(['starts_at' => $at]);
            $this->forecast()->assertJsonPath('event_count', $count);
        }
    }

    public function test_current_weekend_and_both_dst_transitions_use_chicago_calendar_days(): void
    {
        foreach (['2026-10-26', '2026-10-31', '2026-11-01'] as $anchor) {
            $this->forecast($anchor)->assertJsonPath('window.starts_on', '2026-10-31')->assertJsonPath('window.ends_before', '2026-11-02')
                ->assertJsonPath('window.starts_at_utc', '2026-10-31 05:00:00')->assertJsonPath('window.ends_before_utc', '2026-11-02 06:00:00');
        }
        $this->forecast('2026-03-08')->assertJsonPath('window.starts_at_utc', '2026-03-07 06:00:00')->assertJsonPath('window.ends_before_utc', '2026-03-09 05:00:00');
        $this->forecast('2026-12-31')->assertJsonPath('window.starts_on', '2027-01-02')->assertJsonPath('window.ends_before', '2027-01-04');
    }

    public function test_costs_reuse_latest_current_snapshots_and_never_substitute_estimate_for_actual(): void
    {
        $this->reserve();
        app(RecordCostSnapshot::class)->execute($this->owner, $this->event->id, $this->input, (string) Str::uuid());
        $this->forecast()->assertJsonPath('estimate.staff_cost_cents', 15500)->assertJsonPath('actual.staff_cost_cents', null)
            ->assertDontSee('Fixture staff pay');
        app(RecordCostSnapshot::class)->execute($this->owner, $this->event->id, [...$this->input, 'expected_revision' => 1, 'coverage_confirmed' => false], (string) Str::uuid());
        $this->forecast()->assertJsonPath('estimate.staff_cost_cents', null)->assertJsonPath('estimate.known_direct_cost_cents', 15500);
        app(RecordCostSnapshot::class)->execute($this->owner, $this->event->id, [...$this->input, 'expected_revision' => 2], (string) Str::uuid());
        DB::table('events')->where('id', $this->event->id)->increment('version');
        $this->forecast()->assertJsonPath('estimate.staff_cost_cents', null)->assertJsonPath('events.0.estimate.current', false);
    }

    public function test_unknown_balances_are_counted_and_events_are_chronological_without_writes(): void
    {
        $this->reserve();
        $other = $this->event->replicate(); $other->forceFill(['starts_at' => '2026-11-07 12:00:00'])->save();
        $before = [DB::table('audit_entries')->count(), DB::table('ledger_entries')->count()];
        $this->forecast()->assertJsonPath('event_count', 2)->assertJsonPath('events.0.event_id', $other->id)
            ->assertJsonPath('pending_balance_cents', 45500)->assertJsonPath('pending_balance_unknown_events', 1)
            ->assertJsonPath('estimate.direct_cost_cents', null)->assertJsonPath('estimate.pending_events', 2);
        $this->assertSame($before, [DB::table('audit_entries')->count(), DB::table('ledger_entries')->count()]);
    }

    public function test_authorization_sandbox_and_invalid_anchor_remain_enforced(): void
    {
        foreach (['', '2026-02-30', '1999-01-01', '2026-11-02T00:00:00Z'] as $anchor) $this->forecast($anchor)->assertUnprocessable();
        config(['waoo.sandbox_finance' => false]); $this->forecast()->assertForbidden();
        config(['waoo.sandbox_finance' => true]); $this->owner->forceFill(['active' => false])->save();
        try {
            app(WeekendForecast::class)->execute($this->owner, ['anchor' => '2026-11-02']);
            $this->fail('Inactive owner read forecast.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertTrue(true); }
        $this->forecast()->assertForbidden();
        $this->owner->forceFill(['active' => true, 'two_factor_confirmed_at' => null])->save(); $this->forecast()->assertForbidden();
        auth()->logout(); $this->app['auth']->forgetGuards(); $this->forecast()->assertUnauthorized();
    }
}
