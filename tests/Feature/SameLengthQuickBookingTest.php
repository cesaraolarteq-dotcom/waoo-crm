<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{PriceVersion, Resource, ServiceVariant, User};
use App\Services\Booking\QuickBook;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Queue, Schema};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SameLengthQuickBookingTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private PriceVersion $package;
    private PriceVersion $one;
    private PriceVersion $two;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'waoo.sandbox_quick_stripe' => true,
            'waoo.sandbox_quick_payments' => true, 'app.url' => 'https://crm.example.test']);
        Http::preventStrayRequests(); Queue::fake();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $price = function (string $name, int $minutes, int $cents): PriceVersion {
            $v = ServiceVariant::create(['name' => $name, 'status' => 'active', 'duration_minutes' => $minutes, 'setup_minutes' => 15, 'breakdown_minutes' => 15,
                'requirements' => [['kind' => 'staff', 'skill' => 'host', 'quantity' => 1]]]);
            return PriceVersion::create(['service_variant_id' => $v->id, 'amount_cents' => $cents, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        };
        $this->package = $price('Música y Juegos', 120, 52500);
        $this->one = $price('Recreadora extra', 60, 7000);
        $this->two = $price('Recreadora extra · 2 h', 120, 14000);
        foreach (['Sofía Méndez', 'Ana López'] as $i => $name) Resource::create(['code' => 'STAFF-'.$i, 'name' => $name, 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'driver']]);
    }

    protected function tearDown(): void
    {
        // This synthetic database only: preserve production rollback guards and immutable model protections.
        if ($this->app && Schema::hasTable('quick_payment_bookings')) {
            foreach (['quick_payment_notifications', 'quick_payment_receipts', 'quick_payment_attempts', 'quick_payment_events', 'quick_payment_bookings'] as $table) DB::table($table)->delete();
            DB::table('quick_stripe_checkout_requests')->delete();
        }
        parent::tearDown();
    }

    public static function paymentModes(): array { return ['paid' => [true], 'unpaid' => [false]]; }

    private function input(bool $paid, PriceVersion $extra): array
    {
        $start = now()->addDays(20)->startOfHour();
        return ['new_customer' => ['name' => 'María González', 'phone' => '+13465550100', 'email' => 'maria@example.test', 'locale' => 'es'],
            'title' => 'Cumpleaños de Sofía', 'event_type' => 'birthday', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHours(2)->toIso8601String(),
            'timezone' => 'America/Chicago', 'address' => '123 Example St', 'postal_code' => '77493', 'travel_fee_cents' => 0,
            'services' => [['price_version_id' => $this->package->id], ['price_version_id' => $extra->id]],
            ...($paid ? ['deposit' => ['method' => 'zelle', 'amount_cents' => (int) (($this->package->amount_cents + $extra->amount_cents) / 2),
                'reference' => 'QB-LENGTH-1', 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true]] : ['deposit_received' => false])];
    }

    #[DataProvider('paymentModes')]
    public function test_a_direct_quick_request_cannot_sell_one_hour_of_extra_staff_for_a_two_hour_package(bool $paid): void
    {
        try { app(QuickBook::class)->execute($this->owner, $this->input($paid, $this->one), (string) Str::uuid()); $this->fail('A mismatched extra reached the booking and finance transaction.'); }
        catch (RecordWriteRejected $x) { $this->assertSame(422, $x->status); }
        $this->assertDatabaseCount('events', 0); $this->assertDatabaseCount('customers', 0); $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('financial_revisions', 0); $this->assertDatabaseCount('quick_stripe_leases', 0);
    }

    #[DataProvider('paymentModes')]
    public function test_a_matching_catalog_price_is_preserved_without_repricing_or_extending_a_replay(bool $paid): void
    {
        $input = $this->input($paid, $this->two); $input['package_price_version_id'] = $this->package->id; $key = (string) Str::uuid();
        $first = app(QuickBook::class)->execute($this->owner, $input, $key);
        $expires = DB::table('quick_stripe_leases')->value('expires_at');
        $this->travel(5)->minutes();
        app(QuickBook::class)->execute($this->owner, $input, $key);
        $this->assertDatabaseCount('events', 1); $this->assertDatabaseCount('financial_revisions', 1);
        $this->assertSame(66500, \App\Models\FinancialRevision::sole()->total_cents);
        $this->assertSame($expires, DB::table('quick_stripe_leases')->value('expires_at'));
        $this->assertSame($first->event->id, \App\Models\Event::sole()->id);
        $this->assertEqualsCanonicalizing([$this->package->id, $this->two->id], \App\Models\EventService::pluck('price_version_id')->all());
        $this->assertDatabaseCount('price_versions', 3);
    }

    #[DataProvider('paymentModes')]
    public function test_the_declared_package_must_be_one_of_the_selected_services(bool $paid): void
    {
        $input = $this->input($paid, $this->two);
        $input['package_price_version_id'] = $this->one->id; // exists in catalog, but was not selected and is itself an extra
        try { app(QuickBook::class)->execute($this->owner, $input, (string) Str::uuid()); $this->fail('Used an unrelated package as duration authority.'); }
        catch (RecordWriteRejected $x) { $this->assertSame(422, $x->status); }
        $this->assertDatabaseCount('events', 0); $this->assertDatabaseCount('ledger_entries', 0);
    }

    #[DataProvider('paymentModes')]
    public function test_legacy_requests_with_one_unambiguous_package_keep_working(bool $paid): void
    {
        app(QuickBook::class)->execute($this->owner, $this->input($paid, $this->two), (string) Str::uuid());
        $this->assertDatabaseCount('events', 1);
        $this->assertSame(66500, \App\Models\FinancialRevision::sole()->total_cents);
    }

    public function test_the_quick_form_selects_the_matching_version_and_keeps_the_primary_package(): void
    {
        $this->actingAs($this->owner);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        \Livewire\Livewire::test(\App\Filament\Pages\QuickBooking::class)->set('data.package_id', (string) $this->package->id)
            ->set('data.extra_ids', [(string) $this->one->id])->assertSet('data.extra_ids', [(string) $this->two->id])
            ->assertSet('data.price_version_ids', [(string) $this->package->id, (string) $this->two->id]);
        $this->assertDatabaseCount('events', 0); $this->assertDatabaseCount('price_versions', 3);
    }
}
