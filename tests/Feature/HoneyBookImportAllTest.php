<?php
namespace Tests\Feature;

use App\Filament\Pages\ImportHoneyBook;
use App\Models\{Character, Event, EventService, ImportedProjectLink, ImportRow, PriceVersion, ServiceVariant};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\HoneyBookFutureScenario;
use Tests\TestCase;

/** Owner 2026-11-04: «Importar todo» matches packages and characters by itself; only the rest needs a person. */
class HoneyBookImportAllTest extends TestCase
{
    use DatabaseMigrations, HoneyBookFutureScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->futureScenario();
    }

    protected function tearDown(): void
    {
        if ($this->app && \Illuminate\Support\Facades\Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        parent::tearDown();
    }

    public function test_import_all_matches_by_name_and_leaves_the_rest_marked(): void
    {
        $minnie = Character::create(['name' => 'Minnie Mouse', 'active' => true]);
        ServiceVariant::create(['name' => 'Photo Booth', 'status' => 'active', 'duration_minutes' => 60, 'setup_minutes' => 60, 'breakdown_minutes' => 30,
            'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'operator']]]);
        $magic = ServiceVariant::create(['name' => 'Mágico (Magic)', 'status' => 'active', 'duration_minutes' => 60, 'setup_minutes' => 15, 'breakdown_minutes' => 15, 'character_quantity' => 1,
            'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'host']],
            'character_options' => [['character_id' => $minnie->id, 'requirements' => [['kind' => 'costume', 'quantity' => 1, 'character_id' => $minnie->id], ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]]]);
        foreach (ServiceVariant::whereIn('name', ['Photo Booth', 'Mágico (Magic)'])->get() as $v) PriceVersion::create(['service_variant_id' => $v->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $row = fn (string $id, string $services, string $chars, string $phone) => $this->futureRow(['honeybook_project_id' => $id, 'services' => $services, 'characters' => $chars,
            'client_email' => strtolower($id).'@example.test', 'client_phone' => $phone, 'package_total' => '100.00', 'travel_fee' => '20.00', 'discount' => '0.00', 'total' => '120.00']);
        $page = Livewire::test(ImportHoneyBook::class)->set('csv', $this->futureFile([
            $row('HB-A', 'Photo Booth Station (470.00)', '', '+13465550101'),
            $row('HB-B', 'Magic Package (375.00)', 'Minnie', '+13465550102'),
            $row('HB-C', 'Magic Package (375.00)', '', '+13465550103'),
            $row('HB-D', 'Mystery Package', '', '+13465550104'),
        ]))->call('stageFile')->assertHasNoErrors();

        $page->call('importAll');
        $this->assertDatabaseCount('events', 0); // nothing without the owner's list review

        $page->set('bulkConfirm', false)->set('bulkReviewed', true)->call('importAll')->assertSee('HoneyBook no dice qué personaje')->assertSee('No reconozco el paquete «Mystery Package»');
        $this->assertSame(2, ImportedProjectLink::count());
        $magicEvent = Event::findOrFail(ImportedProjectLink::where('project_key', ImportRow::where('source_line', 3)->value('source_key'))->value('event_id'));
        $this->assertSame('draft', $magicEvent->status);
        $this->assertSame(15, $magicEvent->setup_minutes);
        $this->assertTrue(EventService::where('event_id', $magicEvent->id)->sole()->operational_ready);
        $this->assertDatabaseCount('ledger_entries', 0);

        $this->assertSame(0, Event::where('status', 'capacity_reserved')->count());

        // The row left for a person opens with the package already chosen; she only adds the character.
        $page->call('openRow', ImportRow::where('source_line', 4)->value('id'))
            ->assertSet('data.price_version_ids', [(string) PriceVersion::where('service_variant_id', $magic->id)->value('id')])->assertSet('data.setup_minutes', 15);
    }

    public function test_import_all_can_confirm_in_staff_later_mode_and_explains_what_it_left(): void
    {
        ServiceVariant::create(['name' => 'Photo Booth', 'status' => 'active', 'duration_minutes' => 60, 'setup_minutes' => 60, 'breakdown_minutes' => 30,
            'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'operator']]]);
        PriceVersion::create(['service_variant_id' => ServiceVariant::where('name', 'Photo Booth')->value('id'), 'amount_cents' => 42000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        \App\Models\Resource::create(['code' => 'OP-1', 'name' => 'Ana Rodríguez', 'kind' => 'staff', 'status' => 'active', 'skills' => ['operator', 'driver']]);
        $row = fn (string $id, string $paid, string $phone) => $this->futureRow(['honeybook_project_id' => $id, 'services' => 'Photo Booth Station (470.00)', 'characters' => '',
            'client_email' => strtolower($id).'@example.test', 'client_phone' => $phone, 'package_total' => '100.00', 'travel_fee' => '20.00', 'discount' => '0.00', 'total' => '120.00',
            'amount_paid' => $paid, 'balance' => number_format(120 - (float) $paid, 2, '.', ''), 'payments' => '2026-10-01|'.$paid.'|Zelle']);
        Livewire::test(ImportHoneyBook::class)->set('csv', $this->futureFile([$row('HB-P1', '60.00', '+13465550111'), $row('HB-P2', '20.00', '+13465550112')]))->call('stageFile')->assertHasNoErrors()
            ->set('bulkReviewed', true)->call('importAll')->assertSee('Pagó menos del 50 %');
        $this->assertSame(2, ImportedProjectLink::count());
        $statuses = Event::orderBy('id')->pluck('status')->all();
        $this->assertSame(['capacity_reserved', 'draft'], $statuses);
        $this->assertDatabaseCount('ledger_entries', 0);
    }
}
