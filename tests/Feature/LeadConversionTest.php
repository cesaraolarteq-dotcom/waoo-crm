<?php
namespace Tests\Feature;

use App\Filament\Pages\QuickBooking as QuickBookingPage;
use App\Models\{ClientDetailsReview, ClientDetailsSubmission, Contract, Customer, Event, FinancialRevision, LedgerEntry, PortalGrant, PriceVersion, QuickBookingRecord, ResourceReservation, ServiceVariant, User};
use App\Services\Booking\{ApproveClientDetails, QuickBook};
use App\Services\Documents\{ActivateTemplateVersion, CreateContract, CreateTemplateVersion, IssueContract};
use App\Services\Portal\IssuePortalGrant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;
use App\Models\{Lead, LeadSubmission};
use App\Services\Leads\{ConvertLead, ReceiveWebsiteLead};
use App\Exceptions\RecordWriteRejected;

class LeadConversionTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'waoo.sandbox_lead_intake' => true, 'waoo.lead_shared_secret' => str_repeat('fixture', 8)]);
        Queue::fake(); Storage::fake('local');
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant = ServiceVariant::create(['name' => 'Fixture Quick Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = now()->addDays(20)->startOfHour();
        $this->input = ['new_customer' => ['name' => 'Provisional Fixture', 'phone' => '555-0100', 'email' => null, 'locale' => 'es'],
            'title' => 'Fixture Quick Party', 'event_type' => 'birthday', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago',
            'postal_code' => '00001', 'travel_fee_cents' => 2500, 'setup_minutes' => 30, 'breakdown_minutes' => 20,
            'services' => [['price_version_id' => $price->id]],
            'deposit' => ['method' => 'zelle', 'amount_cents' => 6200, 'reference' => 'QB-FIXTURE-1', 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true],
            'portal' => ['expires_at' => now()->addDays(10)->toIso8601String()]];
    }

    private function lead(): Lead
    {
        $body = json_encode(['name' => 'Website fixture', 'phone' => '555-0101', 'email' => 'website@example.invalid', 'event_date' => now()->addDays(20)->toDateString(), 'start_time' => null,
            'zip' => '77493', 'event_type' => 'birthday', 'package' => 'opaque-website-code', 'message' => null, 'travel_estimate_text' => '$999 informational only', 'locale' => 'en',
            'consent' => null, 'demand_flag' => 'unknown', 'submitted_at' => now()->subHour()->toIso8601String(), 'page' => '/contact']);
        $key = (string) Str::uuid(); $at = (string) now()->getTimestamp();
        return app(ReceiveWebsiteLead::class)->execute($body, $at, $key, 'sha256='.hash_hmac('sha256', $at."\n".$key."\n".$body, config('waoo.lead_shared_secret')));
    }
    private function convert(Lead $lead, ?array $booking = null, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/admin/api/leads/'.$lead->id.'/quick-book', ['expected_version' => 1, 'booking' => $booking ?? $this->input]);
    }
    public function test_real_intake_to_quick_booking_links_only_after_verified_core_and_replay_is_safe(): void
    {
        $lead = $this->lead(); $key = (string) Str::uuid();
        $this->assertDatabaseCount('customers', 0); $this->assertDatabaseCount('events', 0);
        $first = $this->convert($lead, key: $key)->assertCreated();
        $this->assertSame('booked', $lead->fresh()->status); $this->assertSame(Event::sole()->id, $lead->fresh()->booked_event_id);
        $this->assertSame(QuickBookingRecord::sole()->id, $lead->fresh()->quick_booking_id); $this->assertSame(2, $lead->fresh()->version);
        $this->assertSame(2500, FinancialRevision::sole()->travel_fee_cents, 'Website estimate is never accepted as a price.');
        $this->assertNotNull($first->json('portal_url')); $this->convert($lead, key: $key)->assertCreated()->assertJsonPath('portal_url', null);
        $this->convert($lead)->assertConflict();
        foreach (['events','customers','ledger_entries','quick_bookings'] as $table) $this->assertDatabaseCount($table, 1);
        $this->patchJson('/admin/api/leads/'.$lead->id, ['expected_version' => 2, 'owner_note' => 'Owner note after successful conversion.'])->assertOk()->assertJsonPath('version', 3);
        $this->patchJson('/admin/api/leads/'.$lead->id, ['expected_version' => 3, 'status' => 'lost'])->assertConflict();
    }
    public function test_invalid_booking_and_audit_failure_leave_lead_and_core_unmodified(): void
    {
        $lead = $this->lead(); $input = $this->input; $input['deposit']['verified_received'] = false;
        $this->convert($lead, $input)->assertUnprocessable();
        $fail = true; DB::connection()->beforeExecuting(function ($query, $bindings) use (&$fail) {
            if ($fail && str_contains($query, 'audit_entries') && in_array('lead.booked', $bindings, true)) throw new \RuntimeException('Fixture conversion audit failure');
        });
        try { app(ConvertLead::class)->execute($this->owner, $lead->id, ['expected_version' => 1, 'booking' => $this->input], (string) Str::uuid()); $this->fail('Audit failure ignored.'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture conversion audit failure', $e->getMessage()); }
        finally { $fail = false; }
        foreach (['events','customers','ledger_entries','quick_bookings','portal_grants'] as $table) $this->assertDatabaseCount($table, 0);
        $this->assertSame('new', $lead->fresh()->status); $this->assertSame(1, $lead->fresh()->version);
        $this->convert($lead)->assertCreated();
    }
    public function test_post_core_portal_failure_keeps_booked_lead_payment_and_pending_link_recovery(): void
    {
        $lead = $this->lead(); config(['app.url' => 'http://insecure.example.invalid']);
        $this->convert($lead)->assertCreated()->assertJsonPath('portal_url', null)->assertJsonPath('link_issue_pending', true);
        $this->assertSame('booked', $lead->fresh()->status); $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertDatabaseHas('payment_exceptions', ['kind' => 'portal_link_pending', 'state' => 'open']);
    }
    public function test_two_real_owners_cannot_convert_the_same_lead_twice(): void
    {
        $id = $this->lead()->id; $input = $this->input;
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $worker = fn ($actor) => function () use ($actor, $id, $input) {
            try { return app(ConvertLead::class)->execute($actor, $id, ['expected_version' => 1, 'booking' => $input], (string) Str::uuid())->event->id; }
            catch (RecordWriteRejected $e) { return $e->status === 409 ? 'conflict' : 'unexpected'; }
        };
        $results = ConcurrentWorkers::run([$worker($this->owner), $worker($other)]);
        $this->assertContains('conflict', $results); $this->assertContains(Event::sole()->id, $results);
        foreach (['events','customers','ledger_entries','quick_bookings'] as $table) $this->assertDatabaseCount($table, 1);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'lead.booked')->count());
    }
    public function test_lost_stale_or_disabled_conversion_is_rejected_even_via_direct_quickbook(): void
    {
        $lead = $this->lead(); $lead->forceFill(['status' => 'lost'])->save(); $this->convert($lead)->assertConflict();
        $lead->forceFill(['status' => 'new', 'version' => 2])->save(); $this->convert($lead)->assertConflict();
        config(['waoo.sandbox_lead_intake' => false]);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/admin/api/quick-bookings', [...$this->input, 'lead_id' => $lead->id, 'lead_expected_version' => 2])->assertForbidden();
        $this->assertDatabaseCount('events', 0);
    }
    public function test_route_quote_is_preserved_through_real_lead_quickbook_without_dto_exposure(): void
    {
        config(['waoo.sandbox_routes' => true]); \Illuminate\Support\Facades\Http::fake();
        $lead = $this->lead(); $address = '123 Fictional Venue, TX 77433';
        $quote = app(\App\Services\Routing\TravelFeeQuote::class)->forAddress($this->owner, $address, '77433');
        $input = [...$this->input, 'address' => $address, 'postal_code' => '77433', 'travel_fee_cents' => 4000, 'travel_fee_quote_id' => $quote['quote_id']];
        $this->convert($lead, $input)->assertCreated()->assertJsonMissingPath('event.travel_fee_evidence');
        $this->assertSame($quote['quote_id'], Event::sole()->travel_fee_evidence['quote_id']);
        $this->assertSame(4000, FinancialRevision::sole()->travel_fee_cents);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_lead_conversion_and_contact_correction_share_rollback_and_success_boundary(): void
    {
        $lead = $this->lead();
        $customer = Customer::create(['name' => 'Fixture existing','email' => 'existing@example.test','locale' => 'es']);
        $input = $this->input; unset($input['new_customer']);
        $input['customer_id'] = $customer->id;
        $input['customer_update'] = ['expected_version' => 2,'name' => 'Fixture corrected','email' => 'corrected@example.test','phone' => null,'locale' => 'en'];
        $this->convert($lead,$input)->assertConflict();
        $this->assertSame('new',$lead->fresh()->status); $this->assertSame(1,$lead->fresh()->version);
        $this->assertSame('existing@example.test',$customer->fresh()->email); $this->assertDatabaseCount('events',0); $this->assertDatabaseCount('ledger_entries',0);
        $input['customer_update']['expected_version'] = 1;
        $this->convert($lead,$input)->assertCreated();
        $this->assertSame('booked',$lead->fresh()->status); $this->assertSame($customer->id,Event::sole()->customer_id);
        $this->assertSame('corrected@example.test',$customer->fresh()->email); $this->assertSame(2,$customer->fresh()->version);
        foreach (['customers','events','ledger_entries','quick_bookings'] as $table) $this->assertDatabaseCount($table,1);
    }

}
