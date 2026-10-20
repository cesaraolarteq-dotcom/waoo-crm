<?php
namespace Tests\Feature;

use App\Filament\Pages\HoneyBookCutover;
use Livewire\Livewire;

use App\Exceptions\RecordWriteRejected;
use App\Models\{CutoverOpeningAgreement, CutoverOpeningSet, CutoverReconciliation, Event, ImportRow, ImportedProjectLink, OperationalReview, Resource, User};
use App\Services\Finance\FinanceSummary;
use App\Services\Imports\{CommitHoneyBookEvent, ConfirmImportedCapacity, PrepareCutoverOpeningAgreements, PrepareHoneyBookCutoverReconciliation,
    ReadCutoverOpeningAgreements, ReadHoneyBookCutoverReadiness, ReadHoneyBookCutoverReview, SaveHoneyBookCutoverReview};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http, Mail, Queue, Schema};
use Illuminate\Support\Str;
use Tests\Support\{ConcurrentWorkers, HoneyBookFutureScenario};
use Tests\TestCase;

// C3 B1 owner section on "Cambio final" (fictitious parties only): preparation only, no collection controls.
class HoneyBookOpeningScreensTest extends TestCase
{
    use DatabaseMigrations, HoneyBookFutureScenario;
    protected function setUp(): void
    {
        parent::setUp(); $this->futureScenario(); config(['waoo.sandbox_cutover' => true, 'waoo.sandbox_cutover_bridge' => true]);
        Http::preventStrayRequests(); Mail::fake();
    }
    protected function tearDown(): void
    {
        if ($this->app) {
            $this->app->detectEnvironment(fn () => 'testing');
            foreach (['cutover_opening_agreements', 'cutover_opening_sets', 'cutover_reconciliations', 'honeybook_cutover_reviews', 'cutover_original_documents'] as $t) if (Schema::hasTable($t)) DB::table($t)->delete();
            if (Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        }
        parent::tearDown();
    }
    private function member(string $project = 'OPENING-FAKE-1', array $source = [], array $review = [], array $commit = []): ImportedProjectLink
    {
        $b = $this->stageFuture(['honeybook_project_id' => $project, ...$source]); $r = ImportRow::where('import_batch_id', $b->id)->sole();
        $link = app(CommitHoneyBookEvent::class)->execute($this->owner, $b->id, $r->id, $this->commitInput($b, $r, $commit), (string)Str::uuid());
        $ids = [Resource::create(['code' => $project.'-HOST', 'name' => 'Fictional host', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'driver']])->id,
            Resource::create(['code' => $project.'-SPKR', 'name' => 'Fictional speaker', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []])->id];
        app(ConfirmImportedCapacity::class)->execute($this->owner, $link->event_id, ['expected_version' => Event::findOrFail($link->event_id)->version,
            'imported_revision_id' => $link->current_revision_id, 'resource_ids' => $ids,
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'PRIVATE_OPERATIONS_NOTE fictional review.', 'transfers' => []]], (string)Str::uuid());
        $v = app(ReadHoneyBookCutoverReview::class)->execute($this->owner, $link->event_id);
        app(SaveHoneyBookCutoverReview::class)->execute($this->owner, $link->event_id, ['expected_version' => $v['expected_version'],
            'expected_binding_sha256' => $v['binding_sha256'], 'file' => UploadedFile::fake()->createWithContent('PRIVATE_FILENAME.pdf', "%PDF-1.4\n% FICTIONAL_ORIGINAL\n%%EOF\n"),
            'owner_attests_signed_original' => true, 'owner_money_reviewed' => true, 'source_observed_at' => now()->subMinute()->toIso8601String(),
            'source_reference' => 'PRIVATE_SOURCE_REFERENCE fictional review', 'owner_note' => 'PRIVATE_REVIEW_NOTE original source checked.',
            'crm_balance_due_at' => '2026-12-19T15:00:00-06:00', 'honeybook_automations_disabled' => true, 'external_collections_retired' => true, ...$review], (string)Str::uuid());
        return $link->fresh();
    }
    private function reconciliation(): CutoverReconciliation
    {
        $v = app(ReadHoneyBookCutoverReadiness::class)->execute($this->owner);
        return app(PrepareHoneyBookCutoverReconciliation::class)->execute($this->owner,
            ['expected_cohort_sha256' => $v['cohort_sha256'], 'source_scope_reviewed' => true], (string)Str::uuid());
    }

    public function test_section_is_hidden_without_the_bridge_flag(): void
    {
        config(['waoo.sandbox_cutover_bridge' => false]);
        Livewire::test(HoneyBookCutover::class)->assertDontSee('Acuerdos de apertura');
    }

    public function test_without_a_current_group_preparation_there_is_no_prepare_button(): void
    {
        $this->member();
        Livewire::test(HoneyBookCutover::class)->assertSee('Acuerdos de apertura (preparación)')
            ->assertSee('Primero prepara la evidencia del grupo completo')->assertDontSee('Preparar acuerdos de');
    }

    public function test_prepare_shows_the_frozen_agreement_and_no_new_money(): void
    {
        $link = $this->member(); $this->reconciliation();
        $page = Livewire::test(HoneyBookCutover::class)->assertSee('Preparar acuerdos de 1 fiestas')
            ->call('prepareOpenings')->assertNotified('Acuerdos de apertura preparados');
        $this->assertSame(1, CutoverOpeningSet::count());
        $page->assertSee(Event::find($link->event_id)->title)->assertSee('Vigentes (solo preparación)')
            ->assertSee('dinero nuevo en el CRM: $0')->assertDontSee('Cobrar');
        // Same reconciliation again: the service reuses the set instead of creating another.
        $page->call('prepareOpenings');
        $this->assertSame(1, CutoverOpeningSet::count());
        $this->assertDatabaseCount('ledger_entries', 0);
        Mail::assertNothingSent();
    }

    public function test_expired_preparation_is_shown_as_a_snapshot_with_the_reason(): void
    {
        $this->member(); $this->reconciliation();
        Livewire::test(HoneyBookCutover::class)->call('prepareOpenings');
        $this->travel(5)->hours();
        Livewire::test(HoneyBookCutover::class)->assertSee('Foto de ese momento')->assertSee('Venció la evidencia del grupo')
            ->assertDontSee('Preparar acuerdos de');
    }
}
