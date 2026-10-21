<?php
namespace Tests\Feature;

use App\Filament\Pages\ImportHistory;
use App\Models\{HistoryImportBatch, HistoryImportRow, User};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Mail, Queue, Schema};
use Livewire\Livewire;
use Tests\Support\HistoricalSourceFixture;
use Tests\TestCase;

// IMPORT-HISTORY delivery A owner screen (fictitious rows only; the owner's real file is never used in tests).
class ImportHistoryPageTest extends TestCase
{
    use DatabaseMigrations, HistoricalSourceFixture;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-19T16:00:00Z'));
        config(['waoo.sandbox_history' => true]);
        Queue::fake(); Mail::fake(); Http::preventStrayRequests();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        if ($this->app && Schema::hasTable('history_import_batches')) {
            DB::table('historical_events')->update(['current_revision_id'=>null]);
            foreach(['history_import_decisions','historical_event_revisions','historical_events','external_customer_links'] as $table)DB::table($table)->delete();
            DB::table('history_import_rows')->delete(); DB::table('history_import_batches')->delete();
        }
        parent::tearDown();
    }

    private function uploaded(array $rows)
    {
        return Livewire::test(ImportHistory::class)->set('csv', $this->historyFile($rows))->call('stageFile')->assertHasNoErrors();
    }

    public function test_hidden_without_the_history_flag_or_in_production(): void
    {
        config(['waoo.sandbox_history' => false]);
        $this->assertFalse(ImportHistory::canAccess());
        config(['waoo.sandbox_history' => true]);
        $this->assertTrue(ImportHistory::canAccess());
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertFalse(ImportHistory::canAccess());
        $this->app->detectEnvironment(fn () => 'testing');
    }

    public function test_registered_page_opens_for_the_owner_and_stays_gated(): void
    {
        $this->get('/admin/historial-honeybook')->assertOk()->assertSee('Historial de HoneyBook')->assertSee('Sube el archivo del historial');
        $this->assertNotEmpty(collect(\Illuminate\Support\Facades\Route::getRoutes())->filter(fn ($r) => $r->uri() === 'admin/historial-honeybook')->all());
        config(['waoo.sandbox_history' => false]);
        $this->get('/admin/historial-honeybook')->assertForbidden();
        config(['waoo.sandbox_history' => true]);
        $this->actingAs(User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_confirmed_at' => null]))
            ->get('/admin/historial-honeybook')->assertRedirect(route('security.two-factor'));
        $this->actingAs(User::factory()->create(['role' => 'staff', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]))
            ->get('/admin/historial-honeybook')->assertForbidden();
    }

    public function test_upload_shows_counts_rows_and_no_import_action(): void
    {
        $page = $this->uploaded([
            $this->historyRow(),
            $this->historyRow(['project_id' => 'fictional-history-2', 'client_email' => '', 'project_status' => 'cancelled']),
            $this->historyRow(['project_id' => 'fictional-history-3', 'event_date' => '2026-11-20']),
        ]);
        $batch = HistoryImportBatch::sole();
        $page->assertSet('batchId', $batch->id)->assertSee('Revisión del archivo '.$batch->id)
            ->assertSee('Filas (3)')->assertSee('Sin correo')->assertSee('Es de hoy o futura: no va al historial')
            ->assertSee('Importar todas las filas sin conflicto');
        // Aggregate list never shows the client's name; only the selected row does.
        $page->assertDontSee('María González');
        foreach (['customers', 'events', 'ledger_entries', 'financial_revisions'] as $t) $this->assertDatabaseCount($t, 0);
        Mail::assertNothingSent(); Http::assertNothingSent();
    }

    public function test_selected_row_shows_original_fields_escaped(): void
    {
        $page = $this->uploaded([$this->historyRow(['project_name' => '<script>alert(1)</script>', 'start_time' => '14:00', 'end_time' => '16:00'])]);
        $row = HistoryImportRow::sole();
        $page->call('showRow', $row->id)->assertSee('Fila seleccionada')->assertSee('María González')
            ->assertSee('$120 <small>', false)->assertSee('2:00 pm')->assertSee('4:00 pm CDT')
            ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')->assertDontSeeHtml('<script>alert(1)</script>');
    }

    public function test_wrong_header_is_explained_in_plain_words(): void
    {
        Livewire::test(ImportHistory::class)
            ->set('csv', $this->historyFile([array_values($this->historyRow())], array_reverse(\App\Services\History\HistoricalSource::HEADERS)))
            ->call('stageFile')->assertHasErrors(['csv'])->assertSee('19 columnas');
        $this->assertDatabaseCount('history_import_batches', 0);
    }

    public function test_discard_pins_the_displayed_version_and_needs_a_reason(): void
    {
        $page = $this->uploaded([$this->historyRow()]);
        $batch = HistoryImportBatch::sole();
        $read = app(\App\Services\History\ReadHistoricalImport::class)->execute($this->owner, $batch->id);
        $page->call('startDiscard', $read['batch']['version'], $read['coverage_sha256'])
            ->call('discard')->assertHasErrors(['discardReason'])
            ->set('discardReason', 'Subí el archivo equivocado')->call('discard')->assertHasNoErrors()->assertSet('batchId', null);
        $this->assertSame('discarded', $batch->fresh()->state);
        $this->assertDatabaseCount('history_import_rows', 0);
    }

    public function test_discard_with_a_stale_pin_is_refused_and_keeps_the_batch(): void
    {
        $page = $this->uploaded([$this->historyRow()]);
        $batch = HistoryImportBatch::sole();
        $page->call('startDiscard', $batch->version, str_repeat('a', 64))->set('discardReason', 'Archivo equivocado')->call('discard')
            ->assertSet('discardPin', [])->assertNotified('No se descartó');
        $this->assertSame('reviewing', $batch->fresh()->state);
        $this->assertDatabaseCount('history_import_rows', 1);
    }

    public function test_a_discarded_batch_link_explains_it_is_gone(): void
    {
        $this->uploaded([$this->historyRow()]);
        $batch = HistoryImportBatch::sole();
        $batch->forceFill(['state' => 'discarded'])->save();
        Livewire::withQueryParams(['lote' => $batch->id])->test(ImportHistory::class)->assertSee('Este archivo ya se descartó.');
    }
    public function test_bulk_button_imports_safe_rows_and_keeps_review_list():void {
        $page=$this->uploaded([$this->historyRow(),$this->historyRow(['project_id'=>'future','event_date'=>'2027-01-01'])]);
        $batch=HistoryImportBatch::sole();$read=app(\App\Services\History\ReadHistoricalImport::class)->execute($this->owner,$batch->id);
        $page->call('startBulk',$read['batch']['version'],$read['coverage_sha256'],$read['bulk']['plan_sha256'])->call('importSafe')->assertNotified('Historial guardado')->assertSee('1 por revisar');
        $this->assertDatabaseCount('historical_events',1);
    }

}
