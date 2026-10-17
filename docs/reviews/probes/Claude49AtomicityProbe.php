<?php
namespace Tests\Review;

use App\Filament\Pages\QuickBooking;
use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, PriceVersion, ServiceVariant, User};
use App\Services\Finance\{CreateFinancialRevision, FinanceSummary, RecordZellePayment};
use App\Services\Records\{SaveCustomer, SaveEventDraft};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

// Reserva rápida against the proposed QuickBook service. The stand-in chains Codex's real services
// (customer, draft, financial revision, verified Zelle) and returns a fictitious one-time link.
class Claude49AtomicityProbe extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private PriceVersion $price;
    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $this->price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
    }

    private function form(array $over = []): array
    {
        return array_replace_recursive(['price_version_ids' => [$this->price->id], 'event_type' => 'birthday', 'title' => 'Cumpleaños de Sofía (ficticio)',
            'date' => '2026-12-19', 'start_time' => '15:00', 'postal_code' => '00001', 'travel_fee' => '25',
            'customer_mode' => 'new', 'name' => 'Ana Ficticia', 'phone' => '555-0100', 'locale' => 'es',
            'deposit' => ['amount' => '62.50', 'reference' => 'ZELLE-QB-1', 'date' => now('America/Chicago')->subDay()->format('Y-m-d'), 'time' => '00:05', 'verified' => true],
            'expires_days' => 14], $over);
    }

    public function test_review_customer_change_survives_rejected_booking(): void
    {
        $client = Customer::create(['name' => 'Fixture Client', 'email' => 'old@example.test', 'locale' => 'es']);
        $this->app->instance(QuickBooking::SERVICE, new class {
            public function execute(User $actor, array $input, string $key): object { throw new \App\Exceptions\RecordWriteRejected(409, 'Fixture booking rejected'); }
        });
        Livewire::test(QuickBooking::class)->fillForm(['customer_id' => $client->id])
            ->fillForm($this->form(['customer_mode' => 'existing', 'customer_id' => $client->id, 'c_email' => 'changed@example.test']))
            ->call('save')->assertNotified('No se guardó');
        $this->assertSame(0, Event::count());
        $this->assertSame('changed@example.test', $client->fresh()->email, 'REPRODUCED: customer write committed despite rejected booking.');
        $this->assertSame(2, $client->fresh()->version);
    }
}
