<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\ConfirmCapacity;
use App\Models\{BookingEvidence, Event, ImportBatch, ImportedProjectLink, ImportRow, Resource};
use App\Services\Imports\CommitHoneyBookEvent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\HoneyBookFutureScenario;
use Tests\TestCase;

// Imported HoneyBook event → "Apartar recursos y confirmar" over Codex's ConfirmImportedCapacity (fictitious data only).
class ImportedCapacityPageTest extends TestCase
{
    use DatabaseMigrations, HoneyBookFutureScenario;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->futureScenario();
        $b = $this->stageFuture(['payments' => '2026-10-01|70.00|Cash;2026-10-02|-10.00|source adjustment']);
        $row = ImportRow::sole();
        $link = app(CommitHoneyBookEvent::class)->execute($this->owner, $b->id, $row->id, $this->commitInput($b, $row), (string) Str::uuid());
        $this->event = Event::findOrFail($link->event_id);
    }

    protected function tearDown(): void
    {
        if ($this->app && \Illuminate\Support\Facades\Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        parent::tearDown();
    }

    private function units(bool $driver): array
    {
        return [Resource::create(['code' => 'IMP-HOST', 'name' => 'Fictional Host', 'kind' => 'staff', 'status' => 'active', 'skills' => $driver ? ['host', 'driver'] : ['host']])->id,
            Resource::create(['code' => 'IMP-SPEAKER', 'name' => 'Fictional Speaker', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []])->id];
    }

    private function filled(array $ids)
    {
        return Livewire::test(ConfirmCapacity::class, ['record' => $this->event->getKey()])
            ->set('data.units.staff', [(string) $ids[0]])->set('data.units.equipment', [(string) $ids[1]])
            ->set('data.staff_confirmed', true)->set('data.cargo_checked', true)->set('data.origin_return_note', 'Salen de la bodega ficticia y regresan ahí.');
    }

    public function test_imported_event_uses_source_money_and_freezes_the_driver_evidence(): void
    {
        $ids = $this->units(true);
        $page = $this->filled($ids)->assertSee('Pagado según HoneyBook')->assertSee('importado de HoneyBook, no verificado en banco')
            ->assertSee('Zelle/efectivo (HoneyBook)')->assertSee('Al menos una de las personas que van debe manejar')
            ->assertDontSee('Depósito recibido')->assertSet('importedRevisionId', ImportedProjectLink::sole()->current_revision_id);
        $page->call('confirm')->assertHasNoErrors()->assertRedirect();
        $this->event->refresh();
        $this->assertSame('capacity_reserved', $this->event->status);
        $evidence = BookingEvidence::where('event_id', $this->event->id)->sole();
        $this->assertSame('honeybook_import', $evidence->method);
        $this->assertSame(6000, $evidence->amount_cents);
        $this->assertSame([$ids[0]], $evidence->staff_assignment_snapshot['assigned_driver_ids']);
        $this->assertDatabaseCount('ledger_entries', 0); // source money never becomes verified CRM money
    }

    public function test_crew_without_a_driver_is_refused_with_the_server_reason_and_nothing_is_reserved(): void
    {
        $this->filled($this->units(false))->call('confirm')->assertNotified('No se apartó nada')->assertNoRedirect();
        $this->assertSame('draft', $this->event->fresh()->status);
        $this->assertDatabaseCount('resource_reservations', 0);
        $this->assertDatabaseCount('booking_evidence', 0);
    }

    public function test_a_new_source_revision_after_opening_the_page_is_refused(): void
    {
        $page = $this->filled($this->units(true));
        // The source was re-imported after the page opened: the link now points to a newer revision.
        $newer = \App\Models\ImportedProjectRevision::sole()->replicate(); $newer->revision = 2; $newer->save();
        ImportedProjectLink::query()->update(['current_revision_id' => $newer->id]);
        $page->call('confirm')->assertNotified('No se apartó nada');
        $this->assertSame('draft', $this->event->fresh()->status);
        $this->assertDatabaseCount('resource_reservations', 0);
    }
    public function test_imported_confirmation_accepts_operational_generic_wardrobe_without_crm_money(): void
    {
        $hero = \App\Models\Character::create(['name' => 'Synthetic imported hero', 'active' => true, 'outfits' => ['0']]);
        $variant = \App\Models\ServiceVariant::findOrFail($this->price->service_variant_id);
        $variant->update(['character_quantity' => 1, 'character_options' => [['character_id' => $hero->id, 'requirements' => [
            ['kind' => 'costume', 'quantity' => 1, 'character_id' => $hero->id], ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]]]);
        $link = ImportedProjectLink::sole(); $batch = $this->stageFuture(); $row = ImportRow::where('import_batch_id', $batch->id)->sole();
        $link = app(CommitHoneyBookEvent::class)->execute($this->owner, $batch->id, $row->id, $this->commitInput($batch, $row, [
            'expected_import_version' => $link->version, 'expected_event_version' => $this->event->version,
            'services' => [['price_version_id' => $this->price->id, 'expected_variant_version' => $variant->version, 'characters' => [['character_id' => $hero->id, 'quantity' => 1]]]],
        ]), (string) Str::uuid());
        $unit = Resource::create(['code' => 'IMP-HEAD', 'name' => 'Synthetic generic head', 'kind' => 'costume', 'status' => 'active', 'character_id' => $hero->id, 'skills' => []]);
        $staff = Resource::create(['code' => 'IMP-PERFORMER', 'name' => 'Synthetic performer', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer']]);
        DB::table('costume_compatibilities')->insert(['staff_id' => $staff->id, 'costume_id' => $unit->id]);
        $ids = [...$this->units(true), $unit->id, $staff->id];
        $key = (string) Str::uuid(); $d = ['expected_version' => $this->event->fresh()->version, 'imported_revision_id' => $link->current_revision_id, 'resource_ids' => $ids,
            'costume_outfits' => [['resource_id' => $unit->id, 'outfit' => '0', 'expected_character_version' => 1]],
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Synthetic origin and return review.', 'transfers' => []]];
        $service = app(\App\Services\Imports\ConfirmImportedCapacity::class); $service->execute($this->owner, $this->event->id, $d, $key); $service->execute($this->owner, $this->event->id, $d, $key);
        $this->assertSame('0', BookingEvidence::sole()->staff_assignment_snapshot['costume_assignments'][0]['outfit']);
        $this->assertDatabaseCount('booking_evidence', 1); $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_short_source_deposit_requires_owner_reason_and_preserves_actual_paid_amount():void
    {
        $link=ImportedProjectLink::sole();
        $batch=$this->stageFuture(['amount_paid'=>'50.00','balance'=>'70.00','payments'=>'2026-10-01|50.00|Cash']);
        $row=ImportRow::where('import_batch_id',$batch->id)->sole();
        app(CommitHoneyBookEvent::class)->execute($this->owner,$batch->id,$row->id,$this->commitInput($batch,$row,['expected_import_version'=>$link->version,'expected_event_version'=>$this->event->version]),(string)Str::uuid());
        $this->event->refresh();
        $page=$this->filled($this->units(true))->assertSee('Por qué se acepta menos del 50 %');
        $page->call('confirm')->assertHasErrors('data.imported_deposit_exception_reason')->assertNoRedirect();
        $this->assertDatabaseCount('booking_evidence',0);$this->assertDatabaseCount('resource_reservations',0);
        $page->set('data.imported_deposit_exception_reason','Owner reviewed the historical short deposit.')->call('confirm')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(5000,BookingEvidence::sole()->amount_cents);
        $this->assertSame('Owner reviewed the historical short deposit.',\App\Models\OperationalReview::sole()->evidence['deposit_exception_reason']);
        $this->assertDatabaseCount('ledger_entries',0);
    }

}
