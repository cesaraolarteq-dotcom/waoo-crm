<?php
namespace Tests\Feature;

use App\Filament\Pages\HoneyBookCutover;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\{Event, HoneyBookCutoverReview, ImportedProjectLink, ImportRow, Resource};
use App\Services\Imports\{CommitHoneyBookEvent, ConfirmImportedCapacity, ReadHoneyBookCutoverReview, SaveHoneyBookCutoverReview};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\HoneyBookFutureScenario;
use Tests\TestCase;

// C3 A owner screens over Codex's evidence services: group page with per-party missing items and a disabled transfer,
// and the event's "Evidencia para el cambio" saving an owner review pinned to the displayed binding (no prechecks).
class HoneyBookCutoverScreensTest extends TestCase
{
    use DatabaseMigrations, HoneyBookFutureScenario;

    protected function setUp(): void { parent::setUp(); $this->futureScenario(); config(['waoo.sandbox_cutover' => true]); }

    protected function tearDown(): void
    {
        if ($this->app) {
            foreach (['cutover_reconciliations', 'honeybook_cutover_reviews', 'cutover_original_documents'] as $t) if (Schema::hasTable($t)) DB::table($t)->delete();
            if (Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        }
        parent::tearDown();
    }

    private function confirmed(): ImportedProjectLink
    {
        $batch = $this->stageFuture(['honeybook_project_id' => 'CUT-UI-1']); $row = ImportRow::where('import_batch_id', $batch->id)->sole();
        $link = app(CommitHoneyBookEvent::class)->execute($this->owner, $batch->id, $row->id, $this->commitInput($batch, $row), (string) Str::uuid());
        $ids = [Resource::create(['code' => 'CUTUI-HOST', 'name' => 'Fictional host', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'driver']])->id,
            Resource::create(['code' => 'CUTUI-SPK', 'name' => 'Fictional speaker', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []])->id];
        app(ConfirmImportedCapacity::class)->execute($this->owner, $link->event_id, ['expected_version' => Event::findOrFail($link->event_id)->version,
            'imported_revision_id' => $link->current_revision_id, 'resource_ids' => $ids,
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Synthetic source travel reviewed.', 'transfers' => []]], (string) Str::uuid());

        return $link->fresh();
    }

    public function test_group_page_lists_missing_evidence_and_keeps_transfer_unavailable(): void
    {
        $link = $this->confirmed();
        Livewire::test(HoneyBookCutover::class)->assertSee('Fiestas que hoy cobra HoneyBook')->assertSee('Falta subir el contrato firmado original')
            ->assertSee('Falta tu revisión de esta fiesta')->assertSee('Todavía no se puede preparar')->assertSee('todavía no está disponible')
            ->assertSee('Abrir la fiesta');
        $this->assertStringContainsString('disabled>Pasar 1 fiestas al CRM', Livewire::test(HoneyBookCutover::class)->html());
        config(['waoo.sandbox_cutover' => false]);
        $this->get('/admin/cambio-final')->assertForbidden();
    }

    public function test_event_evidence_action_saves_a_pinned_review_reusing_the_original_and_nothing_is_prechecked(): void
    {
        $link = $this->confirmed();
        $v = app(ReadHoneyBookCutoverReview::class)->execute($this->owner, $link->event_id);
        app(SaveHoneyBookCutoverReview::class)->execute($this->owner, $link->event_id, ['expected_version' => $v['expected_version'], 'expected_binding_sha256' => $v['binding_sha256'],
            'file' => UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n% FICTIONAL\n%%EOF\n"), 'owner_attests_signed_original' => true, 'owner_money_reviewed' => true,
            'source_observed_at' => now()->subMinutes(5)->toIso8601String(), 'source_reference' => 'Fixture first review', 'owner_note' => 'Fixture first note',
            'crm_balance_due_at' => '2026-12-19T15:00:00-06:00', 'honeybook_automations_disabled' => false, 'external_collections_retired' => false], (string) Str::uuid());
        $page = Livewire::test(ViewEvent::class, ['record' => $link->event_id])->assertSee('Evidencia para el cambio')->mountAction('cutoverEvidence')
            ->assertActionDataSet(['reuse_document' => true, 'owner_attests_signed_original' => null, 'owner_money_reviewed' => null, 'honeybook_automations_disabled' => null]);
        $page->setActionData(['reuse_document' => true, 'owner_attests_signed_original' => true, 'owner_money_reviewed' => true,
            'observed_date' => now('America/Chicago')->toDateString(), 'observed_time' => now('America/Chicago')->subMinutes(2)->format('H:i'),
            'source_reference' => 'Fixture second review', 'owner_note' => 'Fixture owner note', 'due_date' => '2026-12-19', 'due_time' => '15:00',
            'honeybook_automations_disabled' => true, 'external_collections_retired' => true])->callMountedAction()->assertNotified('Evidencia guardada');
        $this->assertSame(2, HoneyBookCutoverReview::where('event_id', $link->event_id)->max('version'));
        $after = app(ReadHoneyBookCutoverReview::class)->execute($this->owner, $link->event_id);
        $this->assertNotContains('honeybook_automations_active', $after['missing']);
    }
}
