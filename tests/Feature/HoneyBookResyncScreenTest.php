<?php
namespace Tests\Feature;

use App\Filament\Pages\ImportHoneyBook;
use App\Models\{Event, ImportedProjectLink, ImportRow, Resource};
use App\Services\Imports\{CommitHoneyBookEvent, ConfirmImportedCapacity};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\HoneyBookFutureScenario;
use Tests\TestCase;

// "Revisar pagos de HoneyBook" (C2) over Codex's diff + resync commit: a confirmed imported party with new source
// payments offers only a money review (never the initial mapping), shows before/after, requires the owner's review and
// note, and updates without rebooking.
class HoneyBookResyncScreenTest extends TestCase
{
    use DatabaseMigrations, HoneyBookFutureScenario;

    protected function setUp(): void { parent::setUp(); $this->futureScenario(); }

    protected function tearDown(): void
    {
        if ($this->app && Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        parent::tearDown();
    }

    private function confirmed(): ImportedProjectLink
    {
        $batch = $this->stageFuture([]);
        $row = ImportRow::where('import_batch_id', $batch->id)->sole();
        $link = app(CommitHoneyBookEvent::class)->execute($this->owner, $batch->id, $row->id, $this->commitInput($batch, $row, []), (string) Str::uuid());
        $ids = [Resource::create(['code' => 'RS-HOST', 'name' => 'Fictional host', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'driver']])->id,
            Resource::create(['code' => 'RS-SPKR', 'name' => 'Fictional speaker', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []])->id];
        app(ConfirmImportedCapacity::class)->execute($this->owner, $link->event_id, ['expected_version' => Event::findOrFail($link->event_id)->version,
            'imported_revision_id' => $link->current_revision_id, 'resource_ids' => $ids,
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Fictional owner reviewed travel and return.', 'transfers' => []]], (string) Str::uuid());

        return $link->fresh();
    }

    public function test_confirmed_party_offers_money_review_and_updates_without_rebooking(): void
    {
        $link = $this->confirmed();
        $eventVersion = Event::findOrFail($link->event_id)->version;
        $batch = $this->stageFuture(['amount_paid' => '90.00', 'balance' => '30.00',
            'payments' => '2026-10-01|70.00|Zelle;2026-10-02|-10.00|source adjustment;2026-10-16|30.00|Cash']);
        $row = ImportRow::where('import_batch_id', $batch->id)->sole();
        $page = Livewire::test(ImportHoneyBook::class)->set('batchId', $batch->id)->assertSee('Revisar pagos')->assertSee('Cambiaron los pagos en HoneyBook')
            ->call('openResync', $row->id)->assertSee('Revisar pagos de HoneyBook')->assertSee('Antes (en el CRM)')->assertSee('Pagado')->assertSee('no están verificados');
        $page->call('applyResync')->assertNotified('Falta tu revisión');
        $page->set('resyncReviewed', true)->set('resyncNote', 'Fixture: balance paid in HoneyBook')->call('applyResync')->assertNotified('Pagos de HoneyBook actualizados')->assertSet('resync', null);
        $this->assertNotSame($link->current_revision_id, $link->fresh()->current_revision_id);
        $this->assertSame($eventVersion, Event::findOrFail($link->event_id)->version, 'no rebooking or event change');
    }

    public function test_value_formatting_never_shows_raw_cents(): void
    {
        $this->assertStringStartsWith('$90', ImportHoneyBook::resyncValue('paid_cents', 9000));
        $this->assertStringContainsString('$30', ImportHoneyBook::resyncValue('payments', [['received_date' => '2026-10-16', 'amount_cents' => 3000, 'method' => 'Cash']]));
        $this->assertSame('Sin fecha', ImportHoneyBook::resyncValue('balance_due_date', null));
    }
}
