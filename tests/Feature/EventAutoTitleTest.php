<?php
namespace Tests\Feature;

use App\Filament\Pages\QuickBooking;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Models\{Character, Customer, PriceVersion, ServiceVariant, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

// Owner naming convention: package + characters + short date (MM/DD, /YY if another year) + client; a typed title is never replaced.
class EventAutoTitleTest extends TestCase
{
    use DatabaseMigrations;

    private PriceVersion $magic;
    private Character $rumi;
    private Character $zoey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-16 12:00', 'America/Chicago'));
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]);
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->rumi = Character::create(['name' => 'Rumi', 'active' => true]);
        $this->zoey = Character::create(['name' => 'Zoey', 'active' => true]);
        $variant = ServiceVariant::create(['name' => 'Mágico (Magic)', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [], 'character_quantity' => 1,
            'character_options' => [['character_id' => $this->rumi->id, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]],
                ['character_id' => $this->zoey->id, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]]]);
        $this->magic = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 37500, 'currency' => 'USD', 'created_by' => $owner->id]);
    }

    public function test_suggestion_format(): void
    {
        $chars = [['character_id' => $this->rumi->id, 'quantity' => 1], ['character_id' => $this->zoey->id, 'quantity' => 1]];
        $this->assertSame('Mágico (Magic) Rumi y Zoey 04/10 Claudia Martínez', EventResource::suggestTitle([$this->magic->id], $chars, '2026-04-10', 'Claudia Martínez'));
        $this->assertSame('Mágico (Magic) 2 Rumi 04/10/27 Claudia Martínez', EventResource::suggestTitle([$this->magic->id], [['character_id' => $this->rumi->id, 'quantity' => 2]], '2027-04-10', 'Claudia Martínez'));
        $this->assertSame('', EventResource::suggestTitle([], [], null, null));
    }

    public function test_event_form_fills_the_title_and_keeps_a_typed_one(): void
    {
        $client = Customer::create(['name' => 'Claudia Martínez', 'locale' => 'es']);
        $page = Livewire::test(CreateEvent::class)
            ->fillForm(['customer_id' => $client->id])->fillForm(['price_version_ids' => [$this->magic->id]])->fillForm(['date' => '2026-12-19'])
            ->assertSchemaStateSet(['title' => 'Mágico (Magic) 12/19 Claudia Martínez']);
        $page->fillForm(['title' => 'Mi nombre propio'])->fillForm(['date' => '2026-12-20'])->assertSchemaStateSet(['title' => 'Mi nombre propio']);
    }

    public function test_quick_booking_uses_the_new_client_name(): void
    {
        Livewire::test(QuickBooking::class)
            ->fillForm(['price_version_ids' => [$this->magic->id]])->fillForm(['date' => '2027-04-10'])->fillForm(['customer_mode' => 'new', 'name' => 'Claudia Martínez'])
            ->assertSchemaStateSet(['title' => 'Mágico (Magic) 04/10/27 Claudia Martínez']);
    }
}
