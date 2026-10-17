<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, PriceVersion, ServiceVariant, User};
use App\Services\Costs\RecordCostSnapshot;
use App\Services\Finance\CreateFinancialRevision;
use App\Services\Records\SaveEventDraft;
use App\Services\Reports\BookingSalesReport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingSalesReportTest extends TestCase
{
    use DatabaseMigrations;
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
        return $this->getJson('/admin/api/reports/bookings?'.$query);
    }
    private function confirm(int $eventId, string $at = '2026-11-05 12:00:00', string $subject = 'events'): void
    {
        DB::table('audit_entries')->insert(['actor_id' => $this->owner->id, 'action' => 'booking.capacity_reserved',
            'subject_type' => $subject, 'subject_id' => $eventId, 'before' => '{}', 'after' => '{}', 'created_at' => $at]);
    }
    private function link(int $eventId, ?int $financeId = null): void
    {
        DB::table('capacity_payment_links')->insert(['event_id' => $eventId, 'financial_revision_id' => $financeId ?? $this->finance->id,
            'ledger_entry_ids' => '[]', 'amount_cents' => 10000, 'method' => 'external_zelle', 'reference' => 'PRIVATE-BANK-'.Str::uuid(),
            'received_at' => '2026-11-01 12:00:00', 'created_at' => '2026-11-05 12:00:00', 'updated_at' => '2026-11-05 12:00:00']);
    }
    public function test_initial_confirmation_date_counts_once_instead_of_event_payment_or_repeat_date(): void
    {
        $this->confirm($this->event->id, '2026-10-01 12:00:00');
        $this->confirm($this->event->id);
        $this->link($this->event->id);
        $this->report()->assertOk()->assertJsonPath('booking_count', 0);
        $this->report('period=month&anchor=2026-10-15')->assertJsonPath('booking_count', 1)->assertJsonPath('package_occurrence_count', 1);
        $this->report('period=month&anchor=2026-11-15')->assertJsonPath('booking_count', 0);
    }
    public function test_packages_come_from_linked_historical_snapshot_and_survive_later_catalog_or_status_changes(): void
    {
        $this->confirm($this->event->id); $this->link($this->event->id);
        $new = $this->finance->replicate();
        $new->forceFill(['revision' => 2, 'lines_snapshot' => [[...$this->finance->lines_snapshot[0], 'name' => 'New unlinked package name']]])->save();
        DB::table('service_variants')->update(['name' => 'Current catalog name']);
        DB::table('events')->where('id', $this->event->id)->update(['status' => 'cancelled', 'starts_at' => '2027-01-01 12:00:00']);
        $this->report()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('booking_count', 1)
            ->assertJsonPath('packages.0.name', 'Fixture package')->assertJsonPath('package_coverage_complete', true)
            ->assertJsonPath('packages.0.occurrence_count', 1)->assertDontSee('Current catalog name')->assertDontSee('New unlinked')
            ->assertDontSee('PRIVATE-BANK')->assertDontSee('Fixture Client')->assertJsonMissingPath('events');
    }
    public function test_repeated_service_occurrences_count_separately_but_not_as_two_bookings(): void
    {
        $lines = $this->finance->lines_snapshot;
        $new = $this->finance->replicate();
        $new->forceFill(['revision' => 2, 'lines_snapshot' => [$lines[0], $lines[0], [...$lines[0], 'name' => 'Historical alternate label']]])->save();
        $this->confirm($this->event->id); $this->link($this->event->id, $new->id);
        $this->report()->assertJsonPath('booking_count', 1)->assertJsonPath('package_occurrence_count', 3)
            ->assertJsonPath('packages.0.name', 'Fixture package')->assertJsonPath('packages.0.occurrence_count', 2)
            ->assertJsonPath('packages.0.booking_count', 1)->assertJsonPath('packages.1.occurrence_count', 1);
    }
    public function test_unknown_legacy_booking_does_not_become_zero_packages_or_hide_known_subset(): void
    {
        $this->confirm($this->event->id); $this->link($this->event->id);
        $other = $this->event->replicate(); $other->save(); $this->confirm($other->id);
        $this->report()->assertJsonPath('booking_count', 2)->assertJsonPath('package_unknown_booking_count', 1)
            ->assertJsonPath('package_coverage_complete', false)->assertJsonPath('package_occurrence_count', null)
            ->assertJsonPath('known_package_occurrence_count', 1)->assertJsonPath('packages.0.booking_count', 1);
        $this->link($other->id); // Wrong-event financial revision is never used.
        $this->report()->assertJsonPath('package_unknown_booking_count', 1)->assertJsonPath('known_package_occurrence_count', 1);
    }
    public function test_empty_or_non_usd_package_snapshot_stays_unknown(): void
    {
        $new = $this->finance->replicate(); $new->forceFill(['revision' => 2, 'lines_snapshot' => []])->save();
        $this->confirm($this->event->id); $this->link($this->event->id, $new->id);
        $this->report()->assertJsonPath('package_unknown_booking_count', 1)->assertJsonPath('package_occurrence_count', null);
        // Separate test fixture revision: no application endpoint mutates historical finance.
        DB::table('capacity_payment_links')->where('event_id', $this->event->id)->delete();
        $new = $this->finance->replicate(); $new->forceFill(['revision' => 3, 'currency' => 'EUR'])->save();
        $this->link($this->event->id, $new->id);
        $this->report()->assertJsonPath('package_unknown_booking_count', 1)->assertJsonPath('package_occurrence_count', null);
    }
    public function test_chicago_boundaries_exclusive_end_and_unrelated_audit_subjects(): void
    {
        $this->confirm($this->event->id, '2026-11-02 05:59:59', 'customers');
        $this->confirm($this->event->id, '2026-11-02 06:00:00'); $this->link($this->event->id);
        $other = $this->event->replicate(); $other->save(); $this->confirm($other->id, '2026-11-09 06:00:00');
        $this->report()->assertJsonPath('booking_count', 1)->assertJsonPath('period.starts_at_utc', '2026-11-02 06:00:00');
        $this->report('period=week&anchor=2026-11-01')->assertJsonPath('booking_count', 0)
            ->assertJsonPath('period.starts_at_utc', '2026-10-26 05:00:00')->assertJsonPath('period.ends_before_utc', '2026-11-02 06:00:00');
    }
    public function test_honeybook_import_confirmation_is_not_a_new_crm_sale(): void
    {
        $this->confirm($this->event->id); $this->link($this->event->id);
        DB::table('imported_project_links')->insert(['project_key' => hash('sha256', 'synthetic-project'),
            'event_id' => $this->event->id, 'customer_id' => $this->event->customer_id,
            'domain_sha256' => hash('sha256', 'synthetic-domain'), 'created_at' => now(), 'updated_at' => now()]);
        $this->report()->assertOk()->assertJsonPath('booking_count', 0)
            ->assertJsonPath('imported_confirmation_count_excluded', 1)->assertJsonPath('packages', []);
    }
    public function test_empty_report_is_known_zero_and_read_does_not_write(): void
    {
        $before = [DB::table('audit_entries')->count(), DB::table('ledger_entries')->count()];
        $this->report()->assertJsonPath('booking_count', 0)->assertJsonPath('package_occurrence_count', 0)
            ->assertJsonPath('package_coverage_complete', true)->assertJsonPath('packages', []);
        $this->assertSame($before, [DB::table('audit_entries')->count(), DB::table('ledger_entries')->count()]);
    }
    public function test_owner_2fa_sandbox_and_period_validation_are_required(): void
    {
        foreach (['', 'period=year&anchor=2026-11-05', 'period=month&anchor=2026-02-30'] as $q) $this->report($q)->assertUnprocessable();
        config(['waoo.sandbox_finance' => false]); $this->report()->assertForbidden();
        config(['waoo.sandbox_finance' => true]); $this->owner->forceFill(['role' => 'staff'])->save(); $this->report()->assertForbidden();
        try { app(BookingSalesReport::class)->execute($this->owner, ['period' => 'week', 'anchor' => '2026-11-05']); $this->fail('Staff read report.'); }
        catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertTrue(true); }
        $this->owner->forceFill(['role' => 'owner_admin', 'two_factor_confirmed_at' => null])->save(); $this->report()->assertForbidden();
        auth()->logout(); $this->app['auth']->forgetGuards(); $this->report()->assertUnauthorized();
    }
}
