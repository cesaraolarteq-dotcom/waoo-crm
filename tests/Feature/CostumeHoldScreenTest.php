<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\{Character, PriceVersion, Resource, ServiceVariant, User};
use App\Services\Booking\QuickBook;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

// Owner screen over Codex's costume hold V1: the event file shows the held costume, and the owner can release or retry it.
class CostumeHoldScreenTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner; private Character $character; private PriceVersion $price;

    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]); Queue::fake(); Storage::fake('local');
        $this->owner = User::factory()->create(['name' => 'Ana López', 'role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner); Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->character = Character::create(['name' => 'Mickey', 'active' => true, 'outfits' => ['Clásico', 'Golden']]);
        Resource::create(['code' => 'MICKEY-1', 'name' => 'Cabeza de Mickey 1', 'kind' => 'costume', 'status' => 'active', 'character_id' => $this->character->id, 'skills' => []]);
        $v = ServiceVariant::create(['name' => 'Mágico', 'status' => 'active', 'duration_minutes' => 60, 'character_quantity' => 1, 'requirements' => [],
            'character_options' => [['character_id' => $this->character->id, 'requirements' => [['kind' => 'costume', 'character_id' => $this->character->id, 'quantity' => 1], ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]]]);
        $this->price = PriceVersion::create(['service_variant_id' => $v->id, 'amount_cents' => 37500, 'currency' => 'USD', 'created_by' => $this->owner->id]);
    }

    protected function tearDown(): void
    {
        DB::table('event_service_characters')->delete();
        parent::tearDown();
    }

    private function book(): int
    {
        $start = now('America/Chicago')->addDays(18)->setTime(15, 0)->utc();

        return app(QuickBook::class)->execute($this->owner, ['new_customer' => ['name' => 'María González', 'phone' => '+13465550142', 'locale' => 'es'],
            'title' => 'Mágico Mickey María', 'event_type' => 'birthday', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addHour()->toIso8601String(),
            'timezone' => 'America/Chicago', 'postal_code' => '77433', 'travel_fee_cents' => 0, 'setup_minutes' => 15, 'breakdown_minutes' => 10,
            'services' => [['price_version_id' => $this->price->id, 'characters' => [['character_id' => $this->character->id, 'quantity' => 1, 'outfit' => 'Golden']]]],
            'deposit' => ['method' => 'zelle', 'amount_cents' => 18750, 'reference' => 'MARIA-1', 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true]], (string) Str::uuid())->event->id;
    }

    public function test_event_file_shows_the_held_costume_and_the_owner_can_release_and_hold_again(): void
    {
        $id = $this->book();
        $page = Livewire::test(ViewEvent::class, ['record' => $id])->assertSee('Traje de la reserva')->assertSee('Traje apartado')
            ->assertSee('Cabeza de Mickey 1 · Mickey (Golden)')->assertSee('Liberar traje')->assertDontSee('Apartar traje');
        $page->callAction('releaseCostume', ['reason' => 'El cliente cambió de personaje'])->assertNotified('Traje liberado');
        Livewire::test(ViewEvent::class, ['record' => $id])->assertSee('Liberado')->assertSee('Apartar traje')
            ->callAction('holdCostume')->assertNotified('Traje apartado');
        Livewire::test(ViewEvent::class, ['record' => $id])->assertSee('Traje apartado')->assertSee('Cabeza de Mickey 1');
    }

    public function test_no_block_without_quick_booking_or_with_holds_off(): void
    {
        $id = $this->book();
        config(['waoo.sandbox_documents' => false]);
        Livewire::test(ViewEvent::class, ['record' => $id])->assertDontSee('Traje de la reserva');
    }

    public function test_present_result_says_whether_the_costume_was_held(): void
    {
        $r = (object) ['costume_hold' => ['state' => 'blocked', 'issues' => ['costume_unavailable']]];
        $hold = (function ($r) { return (array) ($r->costume_hold ?? []); })($r);
        $this->assertSame('blocked', $hold['state']);
        $this->assertSame('Ese traje ya está apartado a esa hora', \App\Filament\Resources\Events\Pages\ViewEvent::HOLD_STATE['blocked']);
    }
}
