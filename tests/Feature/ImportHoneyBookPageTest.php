<?php
namespace Tests\Feature;

use App\Filament\Pages\ImportHoneyBook;
use App\Models\{Customer, Event, ImportBatch, ImportedLedgerLine, ImportedProjectLink, ImportRow, ImportServiceMapping, User};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\HoneyBookFutureScenario;
use Tests\TestCase;

// IMPORT-01 owner screen over Codex's fixed 24-column import (fictitious rows only; no real export is ever used in tests).
class ImportHoneyBookPageTest extends TestCase
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

    private function uploaded(array $rows)
    {
        return Livewire::test(ImportHoneyBook::class)->set('csv', $this->futureFile($rows))->call('upload')->assertHasNoErrors();
    }

    private function fillRow($page, array $change = [])
    {
        return $page->set('data.customer_mode', 'new')->set('data.locale', 'es')->set('data.price_version_ids', [(string) $this->price->id])
            ->set('data.setup_minutes', 15)->set('data.breakdown_minutes', 15)->set('data.source_confirmed', true)->set('data.source_evidence_reviewed', true)
            ->set('data.remember_mapping', true)->fill(collect($change)->mapWithKeys(fn ($v, $k) => ["data.$k" => $v])->all());
    }

    public function test_upload_shows_counts_totals_and_explains_rows_that_cannot_be_imported(): void
    {
        $page = $this->uploaded([
            $this->futureRow(['honeybook_project_id' => 'HB-FAKE-1', 'status' => 'client']),
            // 3% card fee included in the source total: equation mismatch, kept as source evidence and not importable.
            $this->futureRow(['honeybook_project_id' => 'HB-FAKE-2', 'total' => '123.60', 'balance' => '63.60']),
            $this->futureRow(['honeybook_project_id' => 'HB-FAKE-3', 'event_date' => '2026-10-01', 'payments' => '2026-09-01|60.00|Zelle']),
        ]);
        $batch = ImportBatch::sole();
        $page->assertSet('batchId', $batch->id)->assertSee('Revisión del lote '.$batch->id)
            ->assertSee('Paquete + transporte − descuento no da el total')->assertSee('El estado no dice "confirmed"')->assertSee('Ya pasó')
            ->assertSee('Diferencia de importes')->assertSee('Omitida');
        $this->assertSame(1, substr_count($page->html(), 'wire:click="openRow('), 'only the status-review row is importable');
        $this->assertDatabaseCount('customers', 0); $this->assertDatabaseCount('events', 0);
    }

    public function test_wrong_header_is_refused_in_plain_spanish(): void
    {
        Livewire::test(ImportHoneyBook::class)->set('csv', $this->futureFile([$this->futureRow()], array_reverse(\App\Services\Imports\HoneyBookFutureSource::HEADERS)))
            ->call('upload')->assertHasErrors('csv');
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_owner_imports_a_row_with_explicit_mapping_and_the_next_row_gets_the_suggestion(): void
    {
        $page = $this->uploaded([$this->futureRow(['honeybook_project_id' => 'HB-FAKE-1', 'status' => 'client']), $this->futureRow(['honeybook_project_id' => 'HB-FAKE-2', 'client_email' => 'second@example.test', 'client_phone' => '+13465550199'])]);
        [$first, $second] = ImportRow::orderBy('source_line')->get()->all();
        $page->call('openRow', $first->id)->assertSee('En el archivo el estado dice "client"')->assertSee('se creará una clienta nueva');
        // Missing confirmations are refused before anything is written.
        $this->fillRow($page, ['source_confirmed' => false])->call('commit')->assertHasErrors('data.source_confirmed');
        $this->assertDatabaseCount('events', 0);
        $this->fillRow($page)->call('commit')->assertHasNoErrors()->assertSet('rowId', null)->assertSee('Importada')->assertSee('Ver evento');
        $link = ImportedProjectLink::sole(); $event = Event::findOrFail($link->event_id);
        $this->assertSame('draft', $event->status); $this->assertSame(2500, $event->travel_fee_cents);
        $this->assertSame([7000, -1000], ImportedLedgerLine::orderBy('source_position')->pluck('amount_cents')->all());
        $this->assertDatabaseCount('ledger_entries', 0); $this->assertDatabaseCount('resource_reservations', 0); $this->assertDatabaseCount('portal_grants', 0);
        $this->assertSame(1, ImportServiceMapping::count());
        // Same HoneyBook package/characters text → remembered equivalence is offered, never applied without the owner's click.
        $page->call('openRow', $second->id)->assertSet('mappingSuggested', true)->assertSet('data.price_version_ids', [(string) $this->price->id])->assertSee('Equivalencia sugerida');
        $this->assertSame(1, ImportedProjectLink::count());
    }

    public function test_existing_client_is_offered_by_phone_and_a_changed_record_is_refused(): void
    {
        $customer = Customer::create(['name' => 'Fictional Existing', 'email' => 'other@example.test', 'phone' => '(346) 555-0100', 'locale' => 'en']);
        $page = $this->uploaded([$this->futureRow()]);
        $page->call('openRow', ImportRow::sole()->id)->assertSet('data.customer_mode', 'existing')->assertSet('data.customer_id', $customer->id);
        $this->fillRow($page, ['customer_mode' => 'existing', 'customer_id' => $customer->id]);
        $customer->refresh()->forceFill(['version' => $customer->version + 1])->save(); // someone edited the client after the panel opened
        $page->call('commit')->assertNotified();
        $this->assertDatabaseCount('imported_project_links', 0); $this->assertDatabaseCount('events', 0);
        $this->assertSame('Fictional Existing', $customer->fresh()->name);
    }

    public function test_finalize_needs_the_coverage_attestation_and_then_closes_the_batch(): void
    {
        $page = $this->uploaded([$this->futureRow()]);
        $page->call('openRow', ImportRow::sole()->id);
        $this->fillRow($page)->call('commit')->assertHasNoErrors()->assertSee('Cerrar lote y borrar el archivo');
        $page->call('finalize')->assertHasErrors('coverageReviewed');
        $page->set('coverageReviewed', true)->call('finalize')->assertNotified();
        $this->assertContains(ImportBatch::sole()->status, ['imported', 'imported_cleanup_pending']);
        $this->assertSame(1, ImportedProjectLink::count());
    }

    public function test_mapping_suggestion_route_reads_by_batch_and_row_ids(): void
    {
        $batch = $this->stageFuture(); $row = ImportRow::sole();
        $this->getJson("/admin/api/imports/honeybook/{$batch->id}/rows/{$row->id}/mapping")->assertOk()->assertJson(['suggestion' => null]);
    }

    public function test_screen_is_owner_only_and_off_without_the_import_sandbox(): void
    {
        $this->get('/admin/importar')->assertOk()->assertSee('Sube el archivo');
        $this->actingAs(User::factory()->create(['role' => 'staff', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]))
            ->get('/admin/importar')->assertForbidden();
        $this->actingAs($this->owner); config(['waoo.sandbox_imports' => false]);
        $this->get('/admin/importar')->assertForbidden();
    }
}
