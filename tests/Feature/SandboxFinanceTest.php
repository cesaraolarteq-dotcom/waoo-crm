<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, PriceVersion, ServiceVariant, User};
use App\Services\Finance\{CreateFinancialRevision, FinanceSummary, RecordZellePayment};
use App\Services\Records\SaveEventDraft;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SandboxFinanceTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private Event $event;
    private FinancialRevision $revision;

    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_finance' => true]);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        $customer = Customer::create(['name' => 'Fixture client', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fixture package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'speaker']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = now()->addDays(20)->startOfHour();
        $this->event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id, 'title' => 'Fixture party', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => 5500, 'services' => [['price_version_id' => $price->id]]]);
        $this->revision = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
    }
    private function payment(array $extra = []): array
    {
        return [...['financial_revision_id' => $this->revision->id, 'amount_cents' => 22705, 'currency' => 'USD', 'reference' => 'FIXTURE-ZELLE-001',
            'received_at' => now()->subHour()->startOfSecond()->toIso8601String(), 'verified_received' => true], ...$extra];
    }
    private function postPayment(array $data, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/admin/api/events/'.$this->event->id.'/payments/zelle', $data);
    }
    public function test_exact_received_amount_and_actual_balance_are_not_rounded_to_deposit(): void
    {
        $this->postPayment($this->payment())->assertCreated()->assertJsonPath('payment.amount_cents', 22705)->assertJsonPath('finance.balance_cents', 22795)
            ->assertJsonPath('finance.deposit_shortfall_cents', 45)->assertJsonPath('finance.balance_label', 'Remaining balance due');
        $this->assertSame(45500, $this->revision->total_cents);
        $this->assertSame(22750, $this->revision->deposit_target_cents);
        $this->assertTrue($this->revision->balance_due_at->equalTo($this->event->starts_at->subHours(24)));
        $this->assertDatabaseCount('resource_reservations', 0);
        $this->assertSame('draft', $this->event->fresh()->status);
        $this->assertDatabaseHas('audit_entries', ['action' => 'payment.zelle_received', 'actor_id' => $this->owner->id]);
    }
    public function test_idempotency_and_duplicate_reference_never_double_credit(): void
    {
        $key = (string) Str::uuid(); $data = $this->payment();
        $first = $this->postPayment($data, $key)->assertCreated()->json('payment.id');
        $this->postPayment($data, $key)->assertCreated()->assertJsonPath('payment.id', $first);
        $alias = (string) Str::uuid();
        $this->postPayment($data, $alias)->assertCreated()->assertJsonPath('payment.id', $first);
        $this->postPayment([...$data, 'amount_cents' => 22760], $alias)->assertConflict();
        $this->postPayment([...$data, 'amount_cents' => 22760])->assertConflict();
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'payment.zelle_received')->count());
    }
    public function test_installments_and_overpayment_keep_credit_visible(): void
    {
        $this->postPayment($this->payment(['amount_cents' => 22760]))->assertCreated()->assertJsonPath('finance.balance_cents', 22740);
        $this->postPayment($this->payment(['reference' => 'FIXTURE-ZELLE-002', 'amount_cents' => 30000]))->assertCreated()
            ->assertJsonPath('finance.net_received_cents', 52760)->assertJsonPath('finance.balance_cents', 0)->assertJsonPath('finance.credit_cents', 7260);
        $this->assertDatabaseCount('ledger_entries', 2);
    }
    public function test_declarations_bad_time_and_missing_idempotency_are_not_received_payments(): void
    {
        $this->postPayment($this->payment(['verified_received' => false]))->assertUnprocessable();
        $this->postPayment($this->payment(['received_at' => now()->addDay()->toIso8601String()]))->assertUnprocessable();
        $this->postPayment($this->payment(['received_at' => '2026-01-01 12:00:00']))->assertUnprocessable();
        $this->postPayment($this->payment(), 'not-a-uuid')->assertUnprocessable();
        $this->assertDatabaseCount('ledger_entries', 0);
    }
    public function test_finance_and_ledger_require_enabled_sandbox_and_enrolled_owner(): void
    {
        config(['waoo.sandbox_finance' => false]); $this->postPayment($this->payment())->assertForbidden();
        config(['waoo.sandbox_finance' => true]);
        $this->actingAs(User::factory()->create(['role' => 'staff']))->getJson('/admin/api/events/'.$this->event->id.'/finance')->assertForbidden();
        $this->assertDatabaseCount('ledger_entries', 0);
    }
    public function test_revisions_are_immutable_stale_edits_reject_and_money_stays_received(): void
    {
        $this->postPayment($this->payment())->assertCreated();
        Event::whereKey($this->event->id)->update(['version' => 2]);
        $this->postPayment($this->payment(['reference' => 'NEW-FIXTURE']))->assertConflict();
        $this->assertSame(22705, app(FinanceSummary::class)->forEvent($this->event->fresh())['received_cents']);
        try { $this->revision->update(['total_cents' => 1]); $this->fail('Revision was mutated.'); }
        catch (\LogicException $e) { $this->assertSame(45500, $this->revision->fresh()->total_cents); }
        try { LedgerEntry::first()->delete(); $this->fail('Receipt was deleted.'); }
        catch (\LogicException $e) { $this->assertDatabaseCount('ledger_entries', 1); }
    }
    public function test_audit_failure_rolls_back_receipt_and_request_key(): void
    {
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into `audit_entries`')) throw new \RuntimeException('Fixture audit failure');
        });
        try {
            app(RecordZellePayment::class)->execute($this->owner, $this->event->id, $this->payment(), (string) Str::uuid());
            $this->fail('Audit failure was ignored.');
        } catch (\RuntimeException $e) { $this->assertSame('Fixture audit failure', $e->getMessage()); }
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('mutation_receipts', 1); // only the revision creation key
    }
    public function test_capacity_failure_keeps_money_and_retry_links_original_receipt_once(): void
    {
        Event::whereKey($this->event->id)->update(['address' => 'Fixture venue', 'setup_minutes' => 10, 'breakdown_minutes' => 10]);
        $speaker = \App\Models\Resource::create(['code' => 'FIXTURE-SPEAKER', 'name' => 'Fixture speaker', 'kind' => 'equipment', 'status' => 'active', 'skills' => [], 'equipment_kind' => 'speaker']);
        $this->postPayment($this->payment())->assertCreated();
        $input = ['expected_version' => 1, 'financial_revision_id' => $this->revision->id, 'resource_ids' => [$speaker->id],
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Fixture origin and return reviewed.', 'transfers' => []]];
        $url = '/admin/api/events/'.$this->event->id.'/confirm-paid-capacity';
        $this->postJson($url, $input)->assertUnprocessable(); // actual receipt is 45 cents short
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertDatabaseCount('resource_reservations', 0);
        $this->assertDatabaseHas('payment_exceptions', ['kind' => 'paid_capacity_pending', 'state' => 'open']);
        $input['review']['deposit_exception_reason'] = 'Fixture owner approves the exact shortfall.';
        $this->postJson($url, $input)->assertOk()->assertJsonPath('capacity_status', 'capacity_reserved');
        $this->postPayment($this->payment(['reference' => 'FIXTURE-BALANCE', 'amount_cents' => 22795]))->assertCreated();
        $this->postJson($url, $input)->assertOk(); // subsequent payment does not change original confirmation evidence
        $this->assertDatabaseCount('capacity_payment_links', 1);
        $this->assertDatabaseCount('resource_reservations', 1);
        $this->assertDatabaseCount('booking_evidence', 1);
        $this->assertDatabaseHas('booking_evidence', ['amount_cents' => 22705, 'verified_by' => $this->owner->id]);
        $this->assertDatabaseHas('payment_exceptions', ['kind' => 'paid_capacity_pending', 'state' => 'resolved']);
        // The reporting read must see the real confirmation/link once, including after retries.
        $anchor = now()->setTimezone('America/Chicago')->toDateString();
        $this->getJson('/admin/api/reports/bookings?period=week&anchor='.$anchor)->assertOk()
            ->assertJsonPath('booking_count', 1)->assertJsonPath('package_occurrence_count', 1)
            ->assertJsonPath('packages.0.name', 'Fixture package')->assertJsonPath('package_unknown_booking_count', 0);

    }

}
