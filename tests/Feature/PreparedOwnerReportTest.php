<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, PriceVersion, ServiceVariant, User};
use App\Services\Costs\RecordCostSnapshot;
use App\Services\Finance\CreateFinancialRevision;
use App\Services\Records\SaveEventDraft;
use App\Services\Reports\PrepareOwnerReport;
use App\Models\OwnerReportSnapshot;
use Tests\Support\ConcurrentWorkers;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class PreparedOwnerReportTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner; private Event $event; private FinancialRevision $finance; private array $input;
    protected function setUp(): void
    {
        parent::setUp(); $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 10:00:00', 'America/Chicago')); \Illuminate\Support\Facades\Mail::fake(); \Illuminate\Support\Facades\Notification::fake(); config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]); Queue::fake();
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
    private function prepare(array $extra = [])
    {
        return $this->postJson('/admin/api/reports/snapshots', [...['kind' => 'weekly', 'as_of' => '2026-11-09'], ...$extra]);
    }
    public function test_weekly_prepares_closed_period_and_canonical_upcoming_weekend_without_sending(): void
    {
        $response = $this->prepare()->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('recipient', 'sales@waooparty.com')->assertJsonPath('period_starts_on', '2026-11-02')
            ->assertJsonPath('period_ends_before', '2026-11-09');
        $json = $response->json('payload_json'); $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(hash('sha256', $json), $response->json('payload_sha256'));
        $this->assertSame('2026-11-09', $payload['due_on']);
        $this->assertSame('2026-11-14', $payload['upcoming_weekend']['window']['starts_on']);
        $this->assertSame(1, $payload['operations']['scheduled']['event_count']);
        $this->assertNull($payload['operations']['scheduled']['actual']['staff_cost_cents']);
        $this->getJson('/admin/api/reports/snapshots/'.$response->json('id'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('payload_sha256', $response->json('payload_sha256'));
        \Illuminate\Support\Facades\Mail::assertNothingSent(); Queue::assertNothingPushed();
        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }
    public function test_monthly_uses_previous_complete_month_not_the_as_of_partial_month(): void
    {
        $response = $this->prepare(['kind' => 'monthly'])->assertCreated()->assertJsonPath('period_starts_on', '2026-10-01')
            ->assertJsonPath('period_ends_before', '2026-11-01');
        $payload = json_decode($response->json('payload_json'), true);
        $this->assertNull($payload['upcoming_weekend']);
        $this->assertSame('2026-11-01', $payload['due_on']);
        $this->assertSame('month', $payload['operations']['period']['kind']);
    }
    public function test_both_admins_and_other_as_of_dates_in_same_period_reuse_exact_frozen_result(): void
    {
        $first = $this->prepare(['as_of' => '2026-11-08'])->assertCreated(); // Previous week Oct26-Nov2.
        DB::table('events')->where('id', $this->event->id)->increment('version');
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($other)->prepare(['as_of' => '2026-11-02'])->assertCreated()->assertJsonPath('id', $first->json('id'))
            ->assertJsonPath('payload_json', $first->json('payload_json'))->assertJsonPath('prepared_by', $this->owner->id);
        $this->assertDatabaseCount('owner_report_snapshots', 1);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'report.snapshot_prepared')->count());
        $this->assertSame('2026-11-07', json_decode($first->json('payload_json'), true)['upcoming_weekend']['window']['starts_on']);
    }
    public function test_snapshot_model_cannot_update_or_delete_history(): void
    {
        $this->prepare()->assertCreated(); $snapshot = OwnerReportSnapshot::sole();
        try { $snapshot->update(['recipient' => 'fixture@example.invalid']); $this->fail('Snapshot updated.'); }
        catch (\LogicException) { $this->assertSame('sales@waooparty.com', $snapshot->fresh()->recipient); }
        try { $snapshot->delete(); $this->fail('Snapshot deleted.'); }
        catch (\LogicException) { $this->assertDatabaseCount('owner_report_snapshots', 1); }
    }
    public function test_failed_audit_rolls_back_snapshot_and_can_retry(): void
    {
        $fail = true;
        DB::connection()->beforeExecuting(function ($query, $bindings) use (&$fail) {
            if ($fail && str_contains($query, 'audit_entries') && in_array('report.snapshot_prepared', $bindings, true)) throw new \RuntimeException('Fixture report audit failure');
        });
        try { app(PrepareOwnerReport::class)->execute($this->owner, ['kind' => 'weekly', 'as_of' => '2026-11-09']); $this->fail('Audit failure ignored.'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture report audit failure', $e->getMessage()); }
        finally { $fail = false; }
        $this->assertDatabaseCount('owner_report_snapshots', 0);
        $this->prepare()->assertCreated();
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'report.snapshot_prepared')->count());
    }
    public function test_concurrent_preparations_commit_one_company_report_and_one_audit(): void
    {
        $actor = $this->owner;
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $worker = fn ($user) => fn () => app(PrepareOwnerReport::class)->execute($user, ['kind' => 'monthly', 'as_of' => '2026-11-09'])->id;
        $results = ConcurrentWorkers::run([$worker($actor), $worker($other)]);
        $this->assertSame($results[0], $results[1]); $this->assertDatabaseCount('owner_report_snapshots', 1);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'report.snapshot_prepared')->count());
    }
    public function test_invalid_future_and_unsupported_prior_period_do_not_store_snapshots(): void
    {
        foreach ([['as_of' => '2026-11-10'], ['as_of' => '2026-02-30'], ['as_of' => '2000-01-01'], ['kind' => 'daily'], ['as_of' => null]] as $input) {
            $this->prepare($input)->assertUnprocessable();
        }
        $this->assertDatabaseCount('owner_report_snapshots', 0);
    }
    public function test_owner_2fa_sandbox_and_anonymous_reads_and_preparations_are_protected(): void
    {
        $id = $this->prepare()->assertCreated()->json('id');
        config(['waoo.sandbox_finance' => false]); $this->prepare()->assertForbidden(); $this->getJson('/admin/api/reports/snapshots/'.$id)->assertForbidden();
        config(['waoo.sandbox_finance' => true]); $this->owner->forceFill(['active' => false])->save();
        try { app(PrepareOwnerReport::class)->execute($this->owner, ['kind' => 'weekly', 'as_of' => '2026-11-09']); $this->fail('Inactive owner prepared report.'); }
        catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertTrue(true); }
        $this->getJson('/admin/api/reports/snapshots/'.$id)->assertForbidden();
        $this->owner->forceFill(['active' => true, 'two_factor_confirmed_at' => null])->save(); $this->prepare()->assertForbidden();
        auth()->logout(); $this->app['auth']->forgetGuards(); $this->getJson('/admin/api/reports/snapshots/'.$id)->assertUnauthorized(); $this->prepare()->assertUnauthorized();
        $this->assertDatabaseCount('owner_report_snapshots', 1);
    }
}
