<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\{Character, Event, PriceVersion, Resource, ResourceReservation, ServiceVariant, User};
use App\Services\Booking\QuickBook;
use App\Services\Changes\CheckRescheduleDate;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// CHANGE-A A2 check only (César 2026-10-29): "¿Está libre esta fecha?" never holds, moves or tells anyone anything.
class RescheduleCheckTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner; private PriceVersion $price; private Character $character;

    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'waoo.sandbox_changes' => true]); Queue::fake(); Storage::fake('local');
        $this->owner = User::factory()->create(['name' => 'Ana López', 'role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]); $this->actingAs($this->owner);
        $this->character = Character::create(['name' => 'Mickey', 'active' => true, 'outfits' => ['0']]);
        Resource::create(['code' => 'MICKEY-1', 'name' => 'Cabeza de Mickey', 'kind' => 'costume', 'status' => 'active', 'character_id' => $this->character->id, 'skills' => []]);
        Resource::create(['code' => 'STAFF-1', 'name' => 'Staff 1', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer', 'driver']]);
        Resource::create(['code' => 'STAFF-2', 'name' => 'Staff 2', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer', 'driver']]);
        foreach (Resource::where('kind', 'staff')->pluck('id') as $sid) DB::table('costume_compatibilities')->insert(['staff_id' => $sid, 'costume_id' => Resource::where('code', 'MICKEY-1')->value('id')]);
        $v = ServiceVariant::create(['name' => 'Música y juegos', 'status' => 'active', 'duration_minutes' => 120, 'character_quantity' => 1, 'requirements' => [],
            'character_options' => [['character_id' => $this->character->id, 'requirements' => [
                ['kind' => 'costume', 'character_id' => $this->character->id, 'quantity' => 1], ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]]]);
        $this->price = PriceVersion::create(['service_variant_id' => $v->id, 'amount_cents' => 30000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        // No HoneyBook calendar and a known drive in tests.
        $this->app->instance('waoo.availability.busy', fn () => []);
        $this->app->instance('waoo.availability.drive', fn () => 15);
    }

    protected function tearDown(): void
    {
        DB::table('event_service_characters')->delete();
        parent::tearDown();
    }

    private function book(int $days, int $hour, string $name = 'María González'): Event
    {
        $start = now('America/Chicago')->addDays($days)->setTime($hour, 0)->utc();
        $b = app(QuickBook::class)->execute($this->owner, ['new_customer' => ['name' => $name, 'phone' => '+1346555'.random_int(1000, 9999), 'locale' => 'es'],
            'title' => 'Cumpleaños de Valentina', 'event_type' => 'birthday', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addHours(2)->toIso8601String(),
            'timezone' => 'America/Chicago', 'address' => '123 Avenida del Parque', 'postal_code' => '77433', 'travel_fee_cents' => 0, 'setup_minutes' => 30, 'breakdown_minutes' => 20,
            'services' => [['price_version_id' => $this->price->id, 'characters' => [['character_id' => $this->character->id, 'quantity' => 1, 'outfit' => '0']]]],
            'deposit' => ['method' => 'zelle', 'amount_cents' => 15000, 'reference' => 'DEP-'.Str::random(8), 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true]], (string) Str::uuid());
        return Event::findOrFail($b->event->id);
    }

    private function ask(Event $e, int $days, string $time): array
    {
        return app(CheckRescheduleDate::class)->execute($this->owner, $e->id, ['date' => now('America/Chicago')->addDays($days)->toDateString(), 'time' => $time]);
    }

    public function test_the_same_party_on_a_free_day_is_free_and_nothing_changes(): void
    {
        $e = $this->book(20, 12);
        $before = [$e->fresh()->toArray(), ResourceReservation::orderBy('id')->get()->toArray(), DB::table('audit_entries')->count()];
        $r = $this->ask($e, 30, '15:00');
        $this->assertSame('available', $r['state']);
        $this->assertEquals(2 * 3600, \Carbon\CarbonImmutable::parse($r['starts_at'])->diffInSeconds(\Carbon\CarbonImmutable::parse($r['ends_at'])), 'same duration');
        $this->assertSame($before, [$e->fresh()->toArray(), ResourceReservation::orderBy('id')->get()->toArray(), DB::table('audit_entries')->count()]);
        $this->assertSame('available', $this->ask($e, 20, '12:30')['state'], 'its own current slot never blocks it');
    }

    public function test_another_party_holding_the_only_mickey_makes_that_time_unavailable(): void
    {
        $e = $this->book(20, 12);
        $other = $this->book(30, 15, 'Lucía Hernández');
        // The other party holds the only Mickey costume when it is confirmed (here: the quick-booking costume hold).
        $this->assertTrue(ResourceReservation::where('event_id', $other->id)->exists() || \App\Models\QuickCostumeHold::where('event_id', $other->id)->exists());
        $this->assertSame('unavailable', $this->ask($e, 30, '15:00')['state']);
        $this->assertSame('available', $this->ask($e, 30, '20:00')['state'], 'well after the other one');
    }

    public function test_bad_input_and_an_unanswered_calendar(): void
    {
        $e = $this->book(20, 12);
        foreach ([['date' => now('America/Chicago')->subDay()->toDateString(), 'time' => '12:00'], ['date' => now('America/Chicago')->addDays(5)->toDateString(), 'time' => '25:00']] as $bad) {
            try { app(CheckRescheduleDate::class)->execute($this->owner, $e->id, $bad); $this->fail('accepted '.json_encode($bad)); } catch (ValidationException) {}
        }
        $this->app->instance('waoo.availability.busy', fn () => null);
        $this->assertSame('review_required', $this->ask($e, 30, '15:00')['state']);
    }

    public function test_the_event_page_button_shows_the_answer(): void
    {
        $e = $this->book(20, 12);
        Livewire::test(ViewEvent::class, ['record' => $e->id])
            ->callAction('rescheduleCheck', ['date' => now('America/Chicago')->addDays(30)->toDateString(), 'time' => '15:00'])
            ->assertHasNoActionErrors()->assertNotified();
        config(['waoo.sandbox_changes' => false]);
        Livewire::test(ViewEvent::class, ['record' => $e->id])->assertActionHidden('rescheduleCheck');
    }
}
