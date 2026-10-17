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
        // Status review and a source total difference are owner-resolvable in the row panel; the past event is not importable.
        $this->assertSame(2, substr_count($page->html(), 'wire:click="openRow('));
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

    public function test_finalize_pins_the_reviewed_coverage_and_never_claims_a_deletion_that_is_pending(): void
    {
        $page = $this->uploaded([$this->futureRow()]);
        $page->call('openRow', ImportRow::sole()->id);
        $this->fillRow($page)->call('commit')->assertHasNoErrors()->assertSee('Cerrar lote y borrar el archivo');
        $page->call('finalize')->assertHasErrors('coverageReviewed');
        $shown = ImportBatch::sole()->version;
        $page->call('attestCoverage', $shown)->assertSet('coverageVersion', $shown);
        // R1: the private file cannot be deleted this time → no "se borró", visible retry, durable evidence kept.
        $real = app(\App\Services\Imports\ImportArtifacts::class);
        app()->instance(\App\Services\Imports\ImportArtifacts::class, new class($real) {
            public function __construct(private $real) {}
            public function delete(string $path): bool { return false; }
            public function __call($m, $a) { return $this->real->{$m}(...$a); }
        });
        $page->call('finalize')->assertNotified('Importación cerrada, falta borrar el archivo')->assertSee('Reintentar borrar el archivo')->assertDontSee('el archivo original se borró');
        $this->assertSame('imported_cleanup_pending', ImportBatch::sole()->status);
        $this->assertSame(2, ImportedLedgerLine::count());
        app()->instance(\App\Services\Imports\ImportArtifacts::class, $real);
        $page->call('finalize')->assertNotified('Lote cerrado')->assertSee('Lote cerrado: el archivo original se borró.');
        $this->assertSame('imported', ImportBatch::sole()->status);
        $this->assertSame(1, ImportedProjectLink::count());
    }

    public function test_coverage_attestation_is_refused_when_the_batch_changed_after_review(): void
    {
        $page = $this->uploaded([$this->futureRow()]);
        $page->call('openRow', ImportRow::sole()->id);
        $this->fillRow($page)->call('commit')->assertHasNoErrors();
        $shown = ImportBatch::sole()->version;
        ImportBatch::query()->increment('version'); // another owner changed the batch after this coverage was displayed
        $page->call('attestCoverage', $shown)->assertSet('coverageVersion', null)->assertNotified('El lote cambió');
        $page->call('finalize')->assertHasErrors('coverageReviewed');
        $this->assertNotSame('imported', ImportBatch::sole()->status);
    }

    public function test_source_total_difference_needs_explicit_acceptance_and_note_and_keeps_original_amounts(): void
    {
        // Synthetic total with a difference (no "3%" is ever written by the page).
        $page = $this->uploaded([$this->futureRow(['total' => '123.60', 'balance' => '63.60'])]);
        $row = ImportRow::sole();
        $page->assertSee('acéptalo tú con una nota')->call('openRow', $row->id)
            ->assertSee('El total de HoneyBook no coincide con la suma')->assertSee('= '.\App\Filament\Support\Labels::money(12000))->assertSee(\App\Filament\Support\Labels::money(12360))->assertSee('+'.\App\Filament\Support\Labels::money(360))
            ->assertSet('data.source_difference_accepted', false)->assertSet('data.source_difference_note', null)->assertDontSee('3 %')->assertDontSee('3%');
        $this->fillRow($page)->call('commit')->assertHasErrors(['data.source_difference_accepted', 'data.source_difference_note']);
        $this->assertDatabaseCount('imported_project_links', 0);
        $this->fillRow($page, ['source_difference_accepted' => true, 'source_difference_note' => 'Incluye el cargo de tarjeta que cobró HoneyBook.'])->call('commit')->assertHasNoErrors();
        $revision = \App\Models\ImportedProjectRevision::sole();
        $this->assertSame(12360, $revision->normalized['total_cents']);
        $this->assertSame(360, $revision->mapping['source_difference']['difference_cents']);
        $this->assertSame('Incluye el cargo de tarjeta que cobró HoneyBook.', $revision->mapping['source_difference']['note']);
        $this->assertDatabaseCount('ledger_entries', 0);
        $page->assertSee('diferencia de total aceptada por ti');
    }

    public function test_rows_without_a_difference_never_send_the_difference_fields_and_cash_shows_as_unverified_zelle(): void
    {
        $page = $this->uploaded([$this->futureRow(['payments' => '2026-10-01|70.00|Cash;2026-10-02|-10.00|source adjustment'])]);
        $page->call('openRow', ImportRow::sole()->id)->assertSee('Zelle (importado de HoneyBook, no verificado en banco)')->assertDontSee('Diferencia en el total de HoneyBook');
        $this->fillRow($page)->call('commit')->assertHasNoErrors();
        $this->assertSame('Cash', ImportedLedgerLine::orderBy('source_position')->value('method')); // raw source text kept
        $this->assertArrayNotHasKey('source_difference', \App\Models\ImportedProjectRevision::sole()->mapping);
    }

    public function test_remembered_mapping_keeps_exact_outfit_and_note_for_the_next_row(): void
    {
        $character = \App\Models\Character::create(['name' => 'Fictional Hero', 'active' => true, 'outfits' => ['0', 'Gala']]);
        $variant = \App\Models\ServiceVariant::create(['name' => 'Fictional Character Package', 'status' => 'active', 'duration_minutes' => 60, 'character_quantity' => 1,
            'character_options' => [['character_id' => $character->id, 'requirements' => [['kind' => 'costume', 'character_id' => $character->id, 'quantity' => 1], ['kind' => 'staff', 'skill' => 'performer', 'quantity' => 1]]]],
            'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'host']]]);
        $price = \App\Models\PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 5000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $page = $this->uploaded([
            $this->futureRow(['honeybook_project_id' => 'HB-FAKE-1', 'services' => 'Hero Package', 'characters' => 'Fictional Hero']),
            $this->futureRow(['honeybook_project_id' => 'HB-FAKE-2', 'services' => ' hero  package ', 'characters' => 'fictional hero', 'client_email' => 'second@example.test', 'client_phone' => '+13465550199']),
        ]);
        [$first, $second] = ImportRow::orderBy('source_line')->get()->all();
        $page->call('openRow', $first->id);
        $chars = [['price_version_id' => (string) $price->id, 'character_id' => (string) $character->id, 'quantity' => 1, 'outfit' => '0', 'note' => 'Bring the cape']];
        $this->fillRow($page, ['price_version_ids' => [(string) $price->id], 'characters' => $chars])->call('commit')->assertHasNoErrors();
        $this->assertSame([['character_id' => $character->id, 'quantity' => 1, 'outfit' => '0', 'note' => 'Bring the cape']], ImportServiceMapping::sole()->selection[0]['characters']);
        // Case/space variants of the same HoneyBook text get the suggestion; outfit "0" and the note survive into the form and the commit.
        $page->call('openRow', $second->id)->assertSet('mappingSuggested', true);
        $shown = array_values($page->get('data.characters')); // repeater items are keyed by UUID
        $this->assertSame(['0', 'Bring the cape'], [$shown[0]['outfit'], $shown[0]['note']]);
        $this->fillRow($page, ['price_version_ids' => [(string) $price->id]])->call('commit')->assertHasNoErrors();
        $chosen = \App\Models\ImportedProjectRevision::orderByDesc('id')->first()->mapping['intent'][0]['characters'][0];
        $this->assertSame(['0', 'Bring the cape'], [$chosen['outfit'], $chosen['note']]);
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
