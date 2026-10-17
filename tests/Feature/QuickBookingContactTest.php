<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer, Event, LedgerEntry, PriceVersion, ServiceVariant, User};
use App\Services\Booking\QuickBook;
use App\Services\Records\SaveCustomer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Illuminate\Support\Str;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class QuickBookingContactTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private Customer $customer;
    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true,'waoo.sandbox_documents' => true,'app.url' => 'https://localhost']);
        Queue::fake(); Storage::fake('local');
        $this->owner = User::factory()->create(['role' => 'owner_admin','active' => true,'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        $this->customer = Customer::create(['name' => 'Fixture original','email' => 'before@example.test','phone' => '555-0100','locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fixture package','status' => 'active','duration_minutes' => 60,'requirements' => [['kind' => 'staff','quantity' => 1,'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id,'amount_cents' => 10000,'currency' => 'USD','created_by' => $this->owner->id]);
        $start = now()->addDays(20)->startOfHour();
        $this->input = ['customer_id' => $this->customer->id, 'customer_update' => ['expected_version' => 1,'name' => 'Fixture corrected','email' => 'after@example.test','phone' => null,'locale' => 'en'],
            'title' => 'Fixture booking','event_type' => 'birthday','starts_at' => $start->toIso8601String(),'ends_at' => $start->copy()->addHour()->toIso8601String(),'timezone' => 'America/Chicago',
            'postal_code' => '00001','travel_fee_cents' => 2500,'setup_minutes' => 15,'breakdown_minutes' => 15,'services' => [['price_version_id' => $price->id]],
            'deposit' => ['method' => 'zelle','amount_cents' => 6250,'reference' => 'FIX-CONTACT-1','received_at' => now()->subMinute()->toIso8601String(),'verified_received' => true],
            'portal' => ['expires_at' => now()->addDays(10)->toIso8601String()]];
    }
    private function book(?array $input = null, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/admin/api/quick-bookings',$input ?? $this->input);
    }
    private function updates(): int
    {
        return DB::table('audit_entries')->where('action','customers.updated')->count();
    }
    private function assertUnchanged(): void
    {
        $this->assertSame(['name' => 'Fixture original','email' => 'before@example.test','phone' => '555-0100','locale' => 'es','version' => 1],$this->customer->fresh()->only(['name','email','phone','locale','version']));
        $this->assertSame(0,$this->updates());
        foreach (['events','ledger_entries','quick_bookings','mutation_receipts','portal_grants'] as $table) $this->assertDatabaseCount($table,0);
    }
    public function test_real_retired_recipe_failure_rolls_back_customer_contact_and_version(): void
    {
        ServiceVariant::query()->update(['status' => 'retired']);
        $this->book()->assertUnprocessable(); $this->assertUnchanged();
    }
    public function test_duplicate_bank_reference_rolls_back_second_contact_edit_and_every_new_core_record(): void
    {
        $first = $this->input; unset($first['customer_update']); $this->book($first)->assertCreated();
        $this->book()->assertConflict();
        $this->assertSame('before@example.test',$this->customer->fresh()->email); $this->assertSame(1,$this->customer->fresh()->version); $this->assertSame(0,$this->updates());
        foreach (['events','ledger_entries','quick_bookings'] as $table) $this->assertDatabaseCount($table,1);
    }
    public function test_late_core_audit_failure_rolls_back_customer_payment_and_idempotency_receipt(): void
    {
        $fail = true;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fail) {
            if ($fail && str_contains($query,'audit_entries') && in_array('quick_booking.created',$bindings,true)) throw new \RuntimeException('Fixture audit rejection');
        });
        try { app(QuickBook::class)->execute($this->owner,$this->input,(string) Str::uuid()); $this->fail('Expected audit rejection'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture audit rejection',$e->getMessage()); }
        finally { $fail = false; }
        $this->assertUnchanged();
    }
    public function test_success_updates_once_and_same_key_retry_does_not_reapply_stale_contact(): void
    {
        $key = (string) Str::uuid();
        $result = $this->book(key:$key)->assertCreated();
        $this->assertSame(2,$this->customer->fresh()->version); $this->assertSame('after@example.test',$this->customer->fresh()->email); $this->assertNull($this->customer->fresh()->phone); $this->assertSame(1,$this->updates());
        $id = $result->json('id');
        app(SaveCustomer::class)->execute($this->owner,['expected_version' => 2,'name' => 'Fixture subsequent edit','email' => 'later@example.test','phone' => null,'locale' => 'es'],$this->customer->id);
        $this->book(key:$key)->assertCreated()->assertJsonPath('id',$id)->assertJsonPath('portal_url',null);
        $this->assertSame('later@example.test',$this->customer->fresh()->email); $this->assertSame(3,$this->customer->fresh()->version); $this->assertSame(2,$this->updates());
        $changed = $this->input; $changed['customer_update']['name'] = 'Different intent';
        $this->book($changed,$key)->assertConflict();
        foreach (['events','ledger_entries','quick_bookings','portal_grants'] as $table) $this->assertDatabaseCount($table,1);
    }
    public function test_stale_version_rejects_even_when_proposed_contact_matches_current_record(): void
    {
        $this->customer->forceFill(['version' => 2])->save();
        $input = $this->input; $input['customer_update'] = [...$this->customer->only(['name','email','phone','locale']),'expected_version' => 1];
        $this->book($input)->assertConflict(); $this->assertSame(0,$this->updates()); $this->assertDatabaseCount('events',0);
    }
    public function test_unchanged_normalized_contact_checks_version_without_an_update_audit(): void
    {
        $input = $this->input; $input['customer_update'] = ['locale' => 'es','phone' => ' 555-0100 ','email' => ' before@example.test ','name' => ' Fixture original ','expected_version' => '1'];
        $this->book($input)->assertCreated();
        $this->assertSame(1,$this->customer->fresh()->version); $this->assertSame(0,$this->updates());
    }
    public function test_invalid_mixed_or_incomplete_contact_payloads_never_write(): void
    {
        $variants = [];
        $invalid = $this->input; $invalid['customer_update']['email'] = 'not-an-email'; $variants[] = $invalid;
        $invalid = $this->input; unset($invalid['customer_update']['expected_version']); $variants[] = $invalid;
        $invalid = $this->input; unset($invalid['customer_update']['phone']); $variants[] = $invalid;
        $invalid = $this->input; unset($invalid['customer_update']['email']); $variants[] = $invalid;
        $invalid = $this->input; $invalid['customer_update']['sms_opt_in'] = true; $variants[] = $invalid;
        $invalid = $this->input; $invalid['customer_update'] = null; $variants[] = $invalid;
        $invalid = $this->input; unset($invalid['customer_id']); $invalid['new_customer'] = ['name' => 'Fixture new','locale' => 'es']; $variants[] = $invalid;
        foreach ($variants as $invalid) $this->book($invalid)->assertUnprocessable();
        $this->assertUnchanged();
    }
    public function test_portal_failure_preserves_whole_committed_result_and_retry_never_recredits(): void
    {
        config(['app.url' => 'http://insecure.example.invalid']);
        $key = (string) Str::uuid();
        $this->book(key:$key)->assertCreated()->assertJsonPath('link_issue_pending',true)->assertJsonPath('portal_url',null);
        $this->assertSame('after@example.test',$this->customer->fresh()->email); $this->assertSame(2,$this->customer->fresh()->version); $this->assertSame(1,$this->updates());
        $this->book(key:$key)->assertCreated()->assertJsonPath('link_issue_pending',true);
        $this->assertSame(1,$this->updates()); $this->assertDatabaseCount('ledger_entries',1); $this->assertDatabaseCount('quick_bookings',1);
        $this->assertDatabaseHas('payment_exceptions',['kind' => 'portal_link_pending','state' => 'open']);
    }
    public function test_two_real_owners_cannot_overwrite_the_same_customer_version_while_booking(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires MariaDB and pcntl');
        $other = User::factory()->create(['role' => 'owner_admin','active' => true,'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at' => now()]);
        $worker = fn ($actor,$label) => function () use ($actor,$label) {
            $input = $this->input; $input['customer_update']['name'] = $label; $input['deposit']['reference'] = 'FIX-'.$label;
            try { app(QuickBook::class)->execute($actor,$input,(string) Str::uuid()); return $label; }
            catch (RecordWriteRejected $e) { return $e->status === 409 ? 'conflict' : 'unexpected'; }
        };
        $results = ConcurrentWorkers::run([$worker($this->owner,'owner-A'),$worker($other,'owner-B')]);
        $this->assertContains('conflict',$results); $winner = $this->customer->fresh()->name; $this->assertContains($winner,['owner-A','owner-B']); $this->assertContains($winner,$results);
        $this->assertSame(2,$this->customer->fresh()->version); $this->assertSame(1,$this->updates());
        foreach (['events','ledger_entries','quick_bookings','portal_grants'] as $table) $this->assertDatabaseCount($table,1);
    }
    public function test_two_actual_identical_retries_change_contact_and_book_only_once(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires MariaDB and pcntl');
        $key = (string) Str::uuid();
        $worker = fn () => app(QuickBook::class)->execute($this->owner,$this->input,$key)->id;
        $results = ConcurrentWorkers::run([$worker,$worker]);
        $this->assertSame($results[0],$results[1]); $this->assertIsInt($results[0]); $this->assertSame(2,$this->customer->fresh()->version); $this->assertSame(1,$this->updates());
        foreach (['events','ledger_entries','quick_bookings','portal_grants'] as $table) $this->assertDatabaseCount($table,1);
    }
}
