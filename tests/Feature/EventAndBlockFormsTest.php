<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\{CreateEvent, EditEvent};
use App\Filament\Resources\Resources\Pages\EditResource;
use App\Filament\Resources\Resources\Widgets\ResourceBlocks;
use App\Models\{Customer, Event, PriceVersion, Resource, ResourceBlock, ServiceVariant, User, Character};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

// Event drafts, blocks and costume compatibility through Codex's real services. Fictitious data only.
class EventAndBlockFormsTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private Customer $customer;
    private PriceVersion $price;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->customer = Customer::create(['name' => 'Fictitious Client', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $this->price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
    }

    private function draft(array $overrides = []): array
    {
        return [...['customer_id' => $this->customer->id, 'title' => 'Fictitious Party', 'date' => '2026-12-19', 'start_time' => '15:00', 'end_time' => '16:00',
            'price_version_ids' => [$this->price->id], 'travel_fee' => '', 'postal_code' => '00001', 'setup_minutes' => 30, 'breakdown_minutes' => 20], ...$overrides];
    }

    public function test_draft_is_saved_in_utc_with_unknown_travel_fee_and_audited(): void
    {
        $this->assertStringContainsString('Fictitious Package · 1 h · $100', implode('|', \App\Filament\Resources\Events\EventResource::priceOptions()));
        Livewire::test(CreateEvent::class)->fillForm($this->draft())->call('create')->assertHasNoFormErrors();
        $event = Event::sole();
        $this->assertSame('2026-12-19T21:00:00+00:00', $event->starts_at->toIso8601String()); // 3 pm CST = UTC-6
        $this->assertNull($event->travel_fee_cents);
        $this->assertSame('draft', $event->status);
        $this->assertSame([$this->price->id], $event->services()->pluck('price_version_id')->all());
        $this->assertSame($this->owner->id, (int) DB::table('audit_entries')->where('action', 'event.created')->value('actor_id'));
        $this->get('/admin/eventos')->assertSee('Pendiente')->assertSee('Nuevo evento');
    }

    public function test_repeated_november_hour_requires_a_choice_and_keeps_it(): void
    {
        Livewire::test(CreateEvent::class)->fillForm($this->draft(['date' => '2026-11-01', 'start_time' => '01:30', 'end_time' => '03:00']))
            ->call('create')->assertHasFormErrors(['start_time_choice']);
        $this->assertSame(0, Event::count());

        Livewire::test(CreateEvent::class)->fillForm($this->draft(['date' => '2026-11-01', 'start_time' => '01:30', 'start_time_choice' => 'cst', 'end_time' => '03:00']))
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('2026-11-01T07:30:00+00:00', Event::sole()->starts_at->toIso8601String());
        Livewire::test(EditEvent::class, ['record' => Event::sole()->id])->assertSchemaStateSet(['start_time' => '01:30', 'start_time_choice' => 'cst']);
    }

    public function test_skipped_march_hour_and_end_before_start_are_refused(): void
    {
        Livewire::test(CreateEvent::class)->fillForm($this->draft(['date' => '2027-03-14', 'start_time' => '02:30', 'end_time' => '04:00']))
            ->call('create')->assertHasFormErrors(['start_time']);
        Livewire::test(CreateEvent::class)->fillForm($this->draft(['start_time' => '16:00', 'end_time' => '15:00']))
            ->call('create')->assertHasFormErrors(['end_time']);
        $this->assertSame(0, Event::count());
    }

    public function test_edit_draft_sets_travel_fee_in_cents_and_booked_events_are_not_editable(): void
    {
        Livewire::test(CreateEvent::class)->fillForm($this->draft())->call('create');
        $event = Event::sole();
        Livewire::test(EditEvent::class, ['record' => $event->id])->fillForm(['travel_fee' => '40.50'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(4050, $event->fresh()->travel_fee_cents);
        $this->assertSame(2, $event->fresh()->version);

        Event::whereKey($event->id)->update(['status' => 'capacity_reserved']);
        $this->get("/admin/eventos/{$event->id}/editar")->assertForbidden();
    }

    public function test_block_and_release_with_history(): void
    {
        $unit = Resource::create(['code' => 'TRJ-1', 'name' => 'Fictitious costume', 'kind' => 'costume', 'status' => 'active', 'character_id' => Character::create(['name' => 'Hero', 'active' => true])->id, 'skills' => []]);
        Livewire::test(ResourceBlocks::class, ['record' => $unit])
            ->callTableAction('block', data: ['start_date' => '2026-12-01', 'start_time' => '09:00', 'end_date' => '2026-12-03', 'end_time' => '18:00', 'reason' => 'Traje en reparación'])
            ->assertNotified('Fechas bloqueadas');
        $block = ResourceBlock::sole();
        $this->assertSame('2026-12-01T15:00:00+00:00', $block->starts_at->toIso8601String());

        Livewire::test(ResourceBlocks::class, ['record' => $unit])->callTableAction('release', $block, data: ['reason' => 'corto'])->assertHasTableActionErrors(['reason']);
        Livewire::test(ResourceBlocks::class, ['record' => $unit])->callTableAction('release', $block, data: ['reason' => 'Regresó de la tintorería y se revisó.'])
            ->assertNotified('Bloqueo levantado');
        $this->assertNotNull($block->fresh()->released_at);
        $this->assertSame($this->owner->id, (int) $block->fresh()->released_by);
        Livewire::test(ResourceBlocks::class, ['record' => $unit])->assertSee('Levantado')->assertSee('Traje en reparación');
    }

    public function test_costume_compatibility_lists_only_performers_and_is_audited(): void
    {
        $hero = Character::create(['name' => 'Hero', 'active' => true]);
        $costume = Resource::create(['code' => 'TRJ-1', 'name' => 'Hero #1', 'kind' => 'costume', 'status' => 'active', 'character_id' => $hero->id, 'skills' => []]);
        $performer = Resource::create(['code' => 'STF-1', 'name' => 'Fictitious Performer', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer']]);
        Resource::create(['code' => 'STF-2', 'name' => 'Fictitious Driver', 'kind' => 'staff', 'status' => 'active', 'skills' => ['driver']]);

        Livewire::test(EditResource::class, ['record' => $costume->id])
            ->callAction('compatibility', ['staff_id' => $performer->id])->assertNotified('Persona agregada');
        $this->assertTrue(DB::table('costume_compatibilities')->where(['staff_id' => $performer->id, 'costume_id' => $costume->id])->exists());
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'costume.compatibility_added')->count());
        $this->assertSame([$performer->id => 'Fictitious Performer'], \App\Models\Resource::query()->where('kind', 'staff')->whereJsonContains('skills', 'performer')->pluck('name', 'id')->all());
    }
}
