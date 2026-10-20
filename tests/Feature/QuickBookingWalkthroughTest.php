<?php
namespace Tests\Feature;

use App\Filament\Pages\QuickBooking;
use App\Filament\Resources\Events\EventResource;
use App\Models\{Customer, PriceVersion, ServiceVariant, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

// Owner's walkthrough of Reserva rápida (2026-10-20): best sellers first, one package picker + extras, the title follows
// the package, likely duplicates while typing a new client, phone or email, deposit dated today with an optional time.
class QuickBookingWalkthroughTest extends TestCase
{
    use DatabaseMigrations;

    private array $prices = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]);
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (['Arte en lienzo' => 60, 'Waoo' => 180, 'Música y Juegos 2.0' => 120, 'Estrella (Star)' => 30, 'Música y Juegos' => 120, 'Mágico (Magic)' => 60, '2 chisperos' => 120] as $name => $min) {
            $v = ServiceVariant::create(['name' => $name, 'status' => 'active', 'duration_minutes' => $min, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'host']]]);
            $this->prices[$name] = PriceVersion::create(['service_variant_id' => $v->id, 'amount_cents' => 30000, 'currency' => 'USD', 'created_by' => $owner->id])->id;
        }
        $this->app->instance(QuickBooking::SERVICE, new class { public array $calls = []; public function execute(...$a) { throw new \RuntimeException('not called'); } });
    }

    public function test_best_sellers_come_first_then_alphabetical(): void
    {
        $names = array_map(fn ($l) => explode(' · ', $l)[0], array_values(EventResource::priceOptions()));
        $this->assertSame(['Estrella (Star)', 'Mágico (Magic)', 'Música y Juegos', 'Música y Juegos 2.0', 'Waoo', '2 chisperos', 'Arte en lienzo'], $names);
    }

    public function test_package_and_extras_feed_the_services_and_the_title_follows_changes(): void
    {
        $p = $this->prices;
        Livewire::test(QuickBooking::class)
            ->fillForm(['package_id' => (string) $p['Arte en lienzo']])
            ->assertSchemaStateSet(['price_version_ids' => [(string) $p['Arte en lienzo']], 'title' => 'Arte en lienzo', 'end_time' => null])
            ->fillForm(['package_id' => null])->assertSchemaStateSet(['price_version_ids' => [], 'title' => ''])
            ->fillForm(['package_id' => (string) $p['Música y Juegos'], 'extra_ids' => [(string) $p['2 chisperos']], 'start_time' => '15:00'])
            ->assertSchemaStateSet(['price_version_ids' => [(string) $p['Música y Juegos'], (string) $p['2 chisperos']], 'title' => 'Música y Juegos + 2 chisperos', 'end_time' => '17:00'])
            ->fillForm(['title' => 'Fiesta de Sofía'])->fillForm(['extra_ids' => []])
            ->assertSchemaStateSet(['title' => 'Fiesta de Sofía']); // typed by hand: never overwritten
    }

    public function test_save_without_a_package_asks_for_it(): void
    {
        Livewire::test(QuickBooking::class)->fillForm(['event_type' => 'birthday', 'title' => 'x', 'date' => '2026-12-19', 'start_time' => '15:00', 'end_time' => '16:00',
            'postal_code' => '77494', 'travel_fee' => '25', 'customer_mode' => 'new', 'name' => 'María González', 'phone' => '346-555-0100', 'locale' => 'es',
            'deposit' => ['amount' => '100', 'reference' => 'ZELLE-1', 'verified' => true]])
            ->call('save')->assertHasFormErrors(['package_id']);
    }

    public function test_typing_a_new_client_lists_registered_ones_that_could_be_her_and_picking_one_uses_her_file(): void
    {
        $maria = Customer::create(['name' => 'María González López', 'phone' => '(346) 216-8471', 'email' => 'maria@example.test', 'locale' => 'es']);
        Customer::create(['name' => 'María Pérez', 'phone' => '281-555-0000', 'locale' => 'en']);
        $this->assertSame([$maria->id], array_keys(QuickBooking::possibleMatches('maría gonzález', null, null)));
        $this->assertSame([$maria->id], array_keys(QuickBooking::possibleMatches('Otra', '3462168471', null)));
        $this->assertSame([$maria->id], array_keys(QuickBooking::possibleMatches(null, null, 'MARIA@example.test')));
        $this->assertStringContainsString('(346) 216-8471 · maria@example.test · 0 eventos', QuickBooking::possibleMatches('González', null, null)[$maria->id]);
        $this->assertCount(2, QuickBooking::possibleMatches('María', null, null));
        $this->assertSame([], QuickBooking::possibleMatches('Ma', '12', 'x'));

        Livewire::test(QuickBooking::class)->fillForm(['customer_mode' => 'new', 'name' => 'Maria Gonzalez'])
            ->assertSee('Clienta')
            ->fillForm(['client_lookup' => 'customer:'.$maria->id])
            ->assertSchemaStateSet(['customer_mode' => 'existing', 'customer_id' => $maria->id, 'c_name' => 'María González López', 'c_phone' => '(346) 216-8471'])
            ->assertSet('displayedCustomerId', $maria->id);
    }

    public function test_new_client_needs_a_phone_or_an_email_and_the_deposit_defaults_to_today(): void
    {
        $page = Livewire::test(QuickBooking::class)->assertSchemaStateSet(['deposit.date' => now('America/Chicago')->format('Y-m-d')])
            ->fillForm(['customer_mode' => 'new', 'name' => 'María González', 'phone' => '', 'email' => ''])->call('save')->assertHasFormErrors(['phone']);
        $page->fillForm(['email' => 'maria@example.test'])->call('save')->assertHasNoFormErrors(['phone']);
    }

    public function test_blank_deposit_time_uses_now_today_or_noon_another_day(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-20T15:30:00-05:00'));
        $page = new QuickBooking; $page->linkExpiryAnchorUtc = now('UTC')->toIso8601String();
        $base = ['customer_mode' => 'new', 'name' => 'María González', 'phone' => '346-555-0100', 'email' => null, 'locale' => 'es', 'title' => 'x', 'event_type' => 'birthday',
            'date' => '2026-12-19', 'start_time' => '15:00', 'end_time' => '16:00', 'postal_code' => '77494', 'travel_fee' => '25', 'price_version_ids' => [$this->prices['Waoo']],
            'characters' => [], 'expires_days' => 14];
        $today = $page->toServiceInput([...$base, 'deposit' => ['amount' => '100', 'reference' => 'Z-1', 'date' => '2026-10-20', 'time' => null, 'verified' => true]]);
        $this->assertSame('2026-10-20T15:29:00-05:00', $today['deposit']['received_at']);
        $other = $page->toServiceInput([...$base, 'deposit' => ['amount' => '100', 'reference' => 'Z-1', 'date' => '2026-10-18', 'time' => '', 'verified' => true]]);
        $this->assertSame('2026-10-18T12:00:00-05:00', $other['deposit']['received_at']);
    }

    public function test_google_address_suggestions_load_only_with_the_browser_key(): void
    {
        Livewire::test(QuickBooking::class)->assertDontSee('waoo-places.js', false)->assertSee('Con la dirección exacta puedes calcular el transporte');
        config(['waoo.google_maps_browser_key' => 'AIzaFixtureBrowserKey']);
        Livewire::test(QuickBooking::class)->assertSee('js/waoo-places.js?v=', false)->assertSee('data-key="AIzaFixtureBrowserKey"', false)
            ->assertSee('data-places="address"', false)->assertSee('data-places="zip"', false)->assertSee('elige la dirección que sugiere Google');
    }

    // PACKAGE-TIMING (Codex af90034): setup/breakdown come from the catalog (max of the chosen services, blank if any is unknown);
    // a value typed by the owner stays; ours follows the selection and clears when it empties.
    public function test_setup_and_breakdown_prefill_from_the_catalog_and_respect_manual_values(): void
    {
        $p = $this->prices;
        ServiceVariant::where('name', 'Waoo')->update(['setup_minutes' => 30, 'breakdown_minutes' => 20]);
        ServiceVariant::where('name', '2 chisperos')->update(['setup_minutes' => 20, 'breakdown_minutes' => null]);
        ServiceVariant::where('name', 'Mágico (Magic)')->update(['setup_minutes' => 15, 'breakdown_minutes' => 10]);
        Livewire::test(QuickBooking::class)
            ->fillForm(['package_id' => (string) $p['Waoo']])->assertSchemaStateSet(['setup_minutes' => '30', 'breakdown_minutes' => '20'])
            ->fillForm(['extra_ids' => [(string) $p['2 chisperos']]])->assertSchemaStateSet(['setup_minutes' => '30', 'breakdown_minutes' => null])
            ->fillForm(['setup_minutes' => '45'])->fillForm(['package_id' => (string) $p['Mágico (Magic)'], 'extra_ids' => []])
            ->assertSchemaStateSet(['setup_minutes' => '45', 'breakdown_minutes' => '10'])
            ->fillForm(['package_id' => null])->assertSchemaStateSet(['setup_minutes' => '45', 'breakdown_minutes' => null]);
        $this->assertSame(['setup_minutes' => 0, 'breakdown_minutes' => null],
            array_intersect_key(\App\Filament\Resources\Variants\ServiceVariantResource::toServiceInput(['name' => 'x', 'duration_minutes' => 60, 'setup_minutes' => '0', 'breakdown_minutes' => '']),
                ['setup_minutes' => 1, 'breakdown_minutes' => 1]));
    }
}
