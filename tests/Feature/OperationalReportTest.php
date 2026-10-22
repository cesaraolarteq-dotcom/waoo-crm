<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, PriceVersion, ServiceVariant, User};
use App\Services\Costs\RecordCostSnapshot;
use App\Services\Finance\CreateFinancialRevision;
use App\Services\Records\SaveEventDraft;
use App\Services\Reports\OperationalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalReportTest extends TestCase
{
    use RefreshDatabase;
    private User $owner; private Event $event; private FinancialRevision $finance; private array $input;
    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]); Queue::fake();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]); $this->actingAs($this->owner);
        $customer = Customer::create(['name' => 'Fixture Client', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fixture package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $this->owner->id]); $start = \Carbon\CarbonImmutable::parse('2026-11-05 15:00:00', 'America/Chicago');
        $this->event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id, 'title' => 'Fixture party', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => 5500, 'services' => [['price_version_id' => $price->id]]]);
        $this->finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        $this->input = ['kind' => 'estimate', 'expected_event_version' => 1, 'financial_revision_id' => $this->finance->id, 'expected_revision' => 0,
            'coverage_confirmed' => true, 'note' => 'Fixture owner entered all direct costs.', 'lines' => [
                ['label' => 'Fixture staff pay', 'category' => 'staff_labor', 'amount_cents' => 12000, 'event_service_id' => $this->finance->lines_snapshot[0]['event_service_id']],
                ['label' => 'Fixture staff transport', 'category' => 'staff_transport', 'amount_cents' => 3500],
            ]];
    }
    private function report(string $query = 'period=week&anchor=2026-11-05')
    {
        return $this->getJson('/admin/api/reports/operations?'.$query);
    }

    private function receipt(string $when, int $amount, string $kind = 'receipt', string $environment = 'test'): void
    {
        LedgerEntry::create(['event_id' => $this->event->id, 'financial_revision_id' => $this->finance->id,
            'environment' => $environment, 'kind' => $kind, 'method' => 'zelle', 'amount_cents' => $amount, 'currency' => 'USD',
            'received_at' => $when, 'external_reference' => 'PRIVATE-BANK-REFERENCE', 'external_identity_key' => (string) Str::uuid(),
            'verified_by' => $this->owner->id, 'source_type' => 'fixture', 'note' => 'PRIVATE-BANK-NOTE']);
    }

    public function test_cash_uses_receipt_dates_local_boundaries_refunds_and_test_environment(): void
    {
        $this->receipt('2026-11-02 06:00:00', 12000); // Monday local midnight, included.
        $this->receipt('2026-11-02 05:59:59', 5000);
        $this->receipt('2026-11-09 05:59:59', 6000);
        $this->receipt('2026-11-09 06:00:00', 9000); // Next Monday, excluded (CST).
        $this->receipt('2026-11-05 12:00:00', 2000, 'refund');
        $this->receipt('2026-11-05 12:00:00', 30000, 'receipt', 'live');
        $this->report()->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('period.starts_on', '2026-11-02')->assertJsonPath('period.ends_before', '2026-11-09')
            ->assertJsonPath('cash.received_cents', 18000)->assertJsonPath('cash.refunded_cents', 2000)->assertJsonPath('cash.net_received_cents', 16000)
            ->assertDontSee('PRIVATE-BANK')->assertDontSee('Fixture Client');
    }

    public function test_dst_transition_weeks_and_months_have_correct_utc_limits(): void
    {
        $this->report('period=week&anchor=2026-11-01')->assertJsonPath('period.starts_at_utc', '2026-10-26 05:00:00')->assertJsonPath('period.ends_before_utc', '2026-11-02 06:00:00');
        $this->report('period=week&anchor=2026-03-08')->assertJsonPath('period.starts_at_utc', '2026-03-02 06:00:00')->assertJsonPath('period.ends_before_utc', '2026-03-09 05:00:00');
        $this->report('period=month&anchor=2026-11-15')->assertJsonPath('period.starts_at_utc', '2026-11-01 05:00:00')->assertJsonPath('period.ends_before_utc', '2026-12-01 06:00:00');
        $this->report('period=month&anchor=2028-02-29')->assertJsonPath('period.ends_before', '2028-03-01');
    }

    public function test_missing_incomplete_stale_and_actual_costs_never_become_zero_or_use_estimates(): void
    {
        $this->report()->assertJsonPath('scheduled.event_count', 1)->assertJsonPath('scheduled.estimate.direct_cost_cents', null)->assertJsonPath('scheduled.actual.staff_cost_cents', null)
            ->assertJsonPath('scheduled.pending_balance_cents', 45500);
        app(RecordCostSnapshot::class)->execute($this->owner, $this->event->id, $this->input, (string) Str::uuid());
        $this->report()->assertJsonPath('scheduled.estimate.direct_cost_cents', 15500)->assertJsonPath('scheduled.estimate.staff_cost_cents', 15500)
            ->assertJsonPath('scheduled.actual.direct_cost_cents', null)->assertDontSee('Fixture staff pay');
        app(RecordCostSnapshot::class)->execute($this->owner, $this->event->id, [...$this->input, 'kind' => 'actual', 'lines' => [
            ['label' => 'Private unknown cost', 'category' => 'materials', 'amount_cents' => null],
        ]], (string) Str::uuid());
        $this->report()->assertJsonPath('scheduled.actual.pending_events', 1)->assertJsonPath('scheduled.actual.direct_cost_cents', null);
        DB::table('events')->where('id', $this->event->id)->increment('version');
        $this->report()->assertJsonPath('scheduled.estimate.direct_cost_cents', null)->assertJsonPath('scheduled.pending_balance_unknown_events', 1)
            ->assertJsonPath('scheduled.events.0.estimate.current', false)->assertJsonPath('scheduled.events.0.balance_cents', null);
    }

    public function test_period_cash_and_current_event_balance_are_separate_and_reads_do_not_write(): void
    {
        $this->receipt('2026-10-01 12:00:00', 10000);
        app(RecordCostSnapshot::class)->execute($this->owner, $this->event->id, [...$this->input, 'kind' => 'actual', 'lines' => [], 'coverage_confirmed' => true], (string) Str::uuid());
        $before = [DB::table('audit_entries')->count(), DB::table('ledger_entries')->count(), DB::table('mutation_receipts')->count()];
        $this->report()->assertJsonPath('cash.received_cents', 0)->assertJsonPath('scheduled.pending_balance_cents', 35500)
            ->assertJsonPath('scheduled.actual.direct_cost_cents', 0)->assertJsonPath('scheduled.actual.staff_cost_cents', 0);
        $this->assertSame($before, [DB::table('audit_entries')->count(), DB::table('ledger_entries')->count(), DB::table('mutation_receipts')->count()]);
        $this->report('period=month&anchor=2027-01-01')->assertJsonPath('scheduled.event_count', 0)->assertJsonPath('scheduled.actual.direct_cost_cents', 0);
    }

    public function test_invalid_periods_dates_and_missing_fields_are_rejected(): void
    {
        foreach (['', 'period=year&anchor=2026-11-01', 'period=week&anchor=2026-02-30', 'period=week&anchor=2026-11-01T12:00:00Z', 'period=month&anchor=1999-12-01'] as $query) {
            $this->report($query)->assertUnprocessable();
        }
    }

    public function test_owner_2fa_gate_and_direct_service_authorization_are_enforced(): void
    {
        config(['waoo.sandbox_finance' => false]); $this->report()->assertForbidden();
        config(['waoo.sandbox_finance' => true]);
        $this->owner->forceFill(['active' => false])->save(); $this->report()->assertForbidden();
        try {
            app(OperationalReport::class)->execute($this->owner, ['period' => 'week', 'anchor' => '2026-11-01']);
            $this->fail('Inactive actor read report.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertTrue(true); }
        $this->owner->forceFill(['active' => true, 'two_factor_confirmed_at' => null])->save(); $this->report()->assertForbidden();
        $this->owner->forceFill(['two_factor_confirmed_at' => now(), 'role' => 'staff'])->save(); $this->report()->assertForbidden();
        auth()->logout(); $this->app['auth']->forgetGuards(); $this->report()->assertUnauthorized();
    }
    public function test_an_unknown_second_event_prevents_a_partial_cost_total_and_latest_revision_wins(): void
    {
        app(RecordCostSnapshot::class)->execute($this->owner, $this->event->id, $this->input, (string) Str::uuid());
        app(RecordCostSnapshot::class)->execute($this->owner, $this->event->id, [...$this->input, 'expected_revision' => 1, 'coverage_confirmed' => false], (string) Str::uuid());
        $this->report()->assertJsonPath('scheduled.estimate.pending_events', 1)->assertJsonPath('scheduled.estimate.direct_cost_cents', null)
            ->assertJsonPath('scheduled.events.0.estimate.revision', 2);
        app(RecordCostSnapshot::class)->execute($this->owner, $this->event->id, [...$this->input, 'expected_revision' => 2], (string) Str::uuid());
        $other = $this->event->replicate(); $other->save();
        $this->report()->assertJsonPath('scheduled.event_count', 2)->assertJsonPath('scheduled.estimate.known_direct_cost_cents', 15500)
            ->assertJsonPath('scheduled.estimate.direct_cost_cents', null)->assertJsonPath('scheduled.estimate.pending_events', 1)
            ->assertJsonPath('scheduled.pending_balance_unknown_events', 1);
    }

    public function test_event_membership_uses_local_start_and_excludes_the_next_period(): void
    {
        DB::table('events')->where('id', $this->event->id)->update(['starts_at' => '2026-11-09 06:00:00']);
        $this->report()->assertJsonPath('scheduled.event_count', 0);
        DB::table('events')->where('id', $this->event->id)->update(['starts_at' => '2026-11-02 06:00:00']);
        $this->report()->assertJsonPath('scheduled.event_count', 1)->assertJsonPath('scheduled.events.0.status', 'draft');
    }

}
