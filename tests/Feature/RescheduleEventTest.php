<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\{Character, Event, PriceVersion, Resource, ResourceReservation, ServiceVariant, User};
use App\Services\Booking\QuickBook;
use App\Services\Changes\RescheduleEvent;
use App\Exceptions\RecordWriteRejected;
use App\Models\{EventChange, QuickCostumeHold};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// CHANGE-A part 1 (César 2026-10-30): move the party with everything it holds, or change nothing.
class RescheduleEventTest extends TestCase
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

    private function move(Event $e, int $days, string $time, ?string $key = null, string $fee = 'waive'): EventChange
    {
        return app(RescheduleEvent::class)->execute($this->owner, $e->id, ['expected_version' => $e->fresh()->version,
            'date' => now('America/Chicago')->addDays($days)->toDateString(), 'time' => $time, 'fee_decision' => $fee, 'fee_reason' => 'La clienta pidió otra fecha.'], $key ?? (string) Str::uuid());
    }

    public function test_the_party_and_its_costume_move_together_and_the_fee_decision_is_recorded(): void
    {
        $e = $this->book(20, 12);
        $this->assertSame(1, ResourceReservation::where('event_id', $e->id)->count(), 'quick booking holds the Mickey costume');
        $c = $this->move($e, 30, '15:00', fee: 'charge');
        $e->refresh();
        $this->assertSame(now('America/Chicago')->addDays(30)->toDateString().' 15:00', $e->starts_at->setTimezone('America/Chicago')->format('Y-m-d H:i'));
        $this->assertSame(2 * 3600, (int) $e->starts_at->diffInSeconds($e->ends_at));
        $r = ResourceReservation::where('event_id', $e->id)->sole();
        $this->assertTrue($r->starts_at->equalTo($e->starts_at) && $r->ends_at->equalTo($e->ends_at));
        $hold = QuickCostumeHold::where('event_id', $e->id)->sole();
        $this->assertSame($e->starts_at->toIso8601String(), $hold->payload['window']['party_start'], 'hold evidence follows the new time');
        \App\Services\Booking\QuickCostumeHolds::verify($hold, $e);
        $this->assertSame(['completed', 10000, 1], [$c->state, $c->fee_cents, $c->moved_reservations]);
    }

    public function test_a_busy_costume_at_the_new_time_changes_nothing(): void
    {
        $e = $this->book(20, 12);
        $other = $this->book(30, 15, 'Lucía Hernández');
        $before = [$e->fresh()->starts_at->toIso8601String(), ResourceReservation::where('event_id', $e->id)->first()->starts_at->toIso8601String()];
        try { $this->move($e, 30, '15:00'); $this->fail('moved onto a busy costume'); } catch (RecordWriteRejected $ex) { $this->assertStringContainsString('ocupado', $ex->getMessage()); }
        $this->assertSame($before, [$e->fresh()->starts_at->toIso8601String(), ResourceReservation::where('event_id', $e->id)->first()->starts_at->toIso8601String()]);
        $this->assertSame(0, EventChange::count());
        \App\Services\Booking\QuickCostumeHolds::verify(QuickCostumeHold::where('event_id', $e->id)->sole(), $e->fresh());
    }

    public function test_same_key_is_one_change_and_a_stale_page_is_refused(): void
    {
        $e = $this->book(20, 12);
        $key = (string) Str::uuid();
        $data = ['expected_version' => $e->version, 'date' => now('America/Chicago')->addDays(25)->toDateString(), 'time' => '16:00', 'fee_decision' => 'waive', 'fee_reason' => 'Cortesía.'];
        $a = app(RescheduleEvent::class)->execute($this->owner, $e->id, $data, $key);
        $b = app(RescheduleEvent::class)->execute($this->owner, $e->id, $data, $key);
        $this->assertSame($a->id, $b->id);
        $this->expectException(RecordWriteRejected::class);
        app(RescheduleEvent::class)->execute($this->owner, $e->id, [...$data, 'time' => '17:00'], (string) Str::uuid());
    }

    public function test_the_event_page_button_moves_the_party(): void
    {
        $e = $this->book(20, 12);
        Livewire::test(ViewEvent::class, ['record' => $e->id])
            ->callAction('reschedule', ['date' => now('America/Chicago')->addDays(28)->toDateString(), 'time' => '13:00', 'fee_decision' => 'waive', 'fee_reason' => 'Cambio por lluvia.'])
            ->assertHasNoActionErrors()->assertNotified();
        $this->assertSame('13:00', $e->fresh()->starts_at->setTimezone('America/Chicago')->format('H:i'));
    }
}
