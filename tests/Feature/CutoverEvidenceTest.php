<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{CutoverOriginalDocument, CutoverReconciliation, Event, FinancialRevision, HoneyBookCutoverReview, ImportRow, ImportedProjectLink, PortalGrant, Resource, User};
use App\Services\Imports\{CommitHoneyBookEvent, ConfirmImportedCapacity, PrepareHoneyBookCutoverReconciliation, ReadHoneyBookCutoverReadiness, ReadHoneyBookCutoverReview, SaveHoneyBookCutoverReview};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt, DB, Queue, Schema};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\{ConcurrentWorkers, HoneyBookFutureScenario};
use Tests\TestCase;

class CutoverEvidenceTest extends TestCase
{
    use DatabaseMigrations, HoneyBookFutureScenario;
    protected function setUp(): void { parent::setUp(); $this->futureScenario(); config(['waoo.sandbox_cutover' => true]); }
    protected function tearDown(): void
    {
        // Remove only this suite's synthetic immutable evidence before the disposable database rollback.
        if ($this->app) {
            $this->app->detectEnvironment(fn () => 'testing');
            foreach (['cutover_reconciliations', 'honeybook_cutover_reviews', 'cutover_original_documents'] as $table) if (Schema::hasTable($table)) DB::table($table)->delete();
            if (Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        }
        parent::tearDown();
    }
    private function confirmed(string $project = 'CUTOVER-FAKE-1', array $change = []): ImportedProjectLink
    {
        $batch = $this->stageFuture(['honeybook_project_id' => $project, ...$change]); $row = ImportRow::where('import_batch_id', $batch->id)->sole();
        $link = app(CommitHoneyBookEvent::class)->execute($this->owner, $batch->id, $row->id, $this->commitInput($batch, $row), (string)Str::uuid());
        $ids = [Resource::create(['code' => $project.'-HOST', 'name' => 'Fictional host', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'driver']])->id,
            Resource::create(['code' => $project.'-SPEAKER', 'name' => 'Fictional speaker', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []])->id];
        app(ConfirmImportedCapacity::class)->execute($this->owner, $link->event_id, ['expected_version' => Event::findOrFail($link->event_id)->version,
            'imported_revision_id' => $link->current_revision_id, 'resource_ids' => $ids,
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Synthetic source travel reviewed.', 'transfers' => []]], (string)Str::uuid());
        return $link->fresh();
    }
    private function pdf(string $suffix = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('PRIVATE_CLIENT_FILENAME.pdf', "%PDF-1.4\n% FICTIONAL_ORIGINAL_ONLY {$suffix}\n%%EOF\n");
    }
    private function input(int $eventId, array $change = []): array
    {
        $v = app(ReadHoneyBookCutoverReview::class)->execute($this->owner, $eventId);
        return ['expected_version' => $v['expected_version'], 'expected_binding_sha256' => $v['binding_sha256'], 'file' => $this->pdf(),
            'owner_attests_signed_original' => true, 'owner_money_reviewed' => true, 'source_observed_at' => now()->subMinute()->toIso8601String(),
            'source_reference' => 'PRIVATE_SOURCE_REFERENCE fixture external account review', 'owner_note' => 'PRIVATE_REVIEW_NOTE owner checked original source.',
            'crm_balance_due_at' => '2026-12-19T15:00:00-06:00', 'honeybook_automations_disabled' => true, 'external_collections_retired' => true, ...$change];
    }
    private function save(int $id, ?array $input = null, ?string $key = null): HoneyBookCutoverReview
    {
        return app(SaveHoneyBookCutoverReview::class)->execute($this->owner, $id, $input ?? $this->input($id), $key ?? (string)Str::uuid());
    }
    private function read(): array { return app(ReadHoneyBookCutoverReadiness::class)->execute($this->owner); }
    private function prepare(?array $view = null, ?string $key = null): CutoverReconciliation
    {
        return app(PrepareHoneyBookCutoverReconciliation::class)->execute($this->owner, ['expected_cohort_sha256' => ($view ?? $this->read())['cohort_sha256'], 'source_scope_reviewed' => true], $key ?? (string)Str::uuid());
    }

    public function test_original_review_and_snapshot_are_encrypted_and_cannot_transfer_or_mutate_operations(): void
    {
        $link = $this->confirmed(); $before = [];
        foreach (['events', 'customers', 'event_services', 'resource_reservations', 'booking_evidence', 'imported_project_revisions', 'imported_ledger_lines'] as $table) $before[$table] = DB::table($table)->get()->toJson();
        $review = $this->save($link->event_id); $view = $this->read();
        $this->assertTrue($view['readiness']['evidence_ready']); $this->assertFalse($view['readiness']['ready']);
        $this->assertSame(['total_cents' => 12000, 'gross_received_cents' => 7000, 'refund_cents' => 1000, 'net_paid_cents' => 6000, 'balance_cents' => 6000, 'credit_cents' => 0], $view['totals']);
        $snapshot = $this->prepare($view);
        $this->assertTrue($this->read()['reconciliation']['current']);
        foreach ($before as $table => $bytes) $this->assertSame($bytes, DB::table($table)->get()->toJson(), $table);
        $raw = DB::table('cutover_original_documents')->sole();
        $this->assertStringNotContainsString('FICTIONAL_ORIGINAL_ONLY', $raw->document_ciphertext);
        $this->assertStringStartsWith('%PDF-', Crypt::decryptString($raw->document_ciphertext));
        foreach (['honeybook_cutover_reviews', 'cutover_reconciliations'] as $table) $this->assertStringNotContainsString('PRIVATE_', DB::table($table)->value('payload'));
        $serialized = json_encode([$review, $snapshot, $view, DB::table('audit_entries')->get()]);
        foreach (['PRIVATE_CLIENT_FILENAME', 'PRIVATE_REVIEW_NOTE', 'PRIVATE_SOURCE_REFERENCE', '123 Fictional Venue', 'fixture-source@example.test', 'FICTIONAL_ORIGINAL_ONLY'] as $secret) $this->assertStringNotContainsString($secret, $serialized);
        foreach (['ledger_entries', 'financial_revisions', 'signature_evidence', 'contracts', 'outbound_messages'] as $table) $this->assertDatabaseCount($table, 0);
        $this->postJson('/admin/api/cutover/reconciliations/'.$snapshot->id.'/transfer')->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_four_hour_expiry_uses_oldest_observation_and_never_upload_or_replay_time(): void
    {
        $first = $this->confirmed(); $second = $this->confirmed('CUTOVER-FAKE-2');
        $observed = now()->subHours(3)->toIso8601String();
        $this->save($first->event_id, $this->input($first->event_id, ['source_observed_at' => $observed]));
        $this->save($second->event_id); $view = $this->read(); $key = (string)Str::uuid(); $snapshot = $this->prepare($view, $key);
        $expiry = $snapshot->expires_at->toIso8601String(); $this->assertSame(now()->addHour()->toIso8601String(), $expiry);
        $this->travel(30)->minutes(); $this->assertSame($snapshot->id, $this->prepare(null)->id);
        $this->assertSame($expiry, $snapshot->fresh()->expires_at->toIso8601String());
        $this->travel(30)->minutes();
        $stale = $this->read(); $this->assertFalse($stale['readiness']['evidence_ready']); $this->assertFalse($stale['reconciliation']['current']);
        $this->assertContains('source_reconciliation_stale', $stale['readiness']['missing']);
        $this->assertSame($snapshot->id, $this->prepare($view, $key)->id, 'Historical receipt replay does not renew evidence');
        try { $this->prepare($stale); $this->fail('Expired evidence reused as new review'); } catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        $this->assertDatabaseCount('cutover_reconciliations', 1);
    }

    public function test_new_member_and_changed_customer_or_resource_invalidate_displayed_preparation(): void
    {
        $link = $this->confirmed(); $this->save($link->event_id); $view = $this->read();
        $this->confirmed('CUTOVER-FAKE-2');
        try { $this->prepare($view); $this->fail('Browser selected subset accepted'); } catch (RecordWriteRejected) {}
        $this->assertSame(2, $this->read()['counts']['events']);
        DB::table('customers')->where('id', $link->customer_id)->increment('version');
        Resource::where('kind', 'staff')->first()->forceFill(['status' => 'inactive'])->save();
        $changed = $this->read();
        $this->assertContains('review_stale', $changed['readiness']['missing']);
        $this->assertContains('capacity_review_required', $changed['readiness']['missing']);
        $this->assertDatabaseCount('cutover_reconciliations', 0);
    }

    public function test_review_version_pins_replay_and_existing_document_reuse_without_rewriting_original(): void
    {
        $link = $this->confirmed(); $input = $this->input($link->event_id); $key = (string)Str::uuid();
        $first = $this->save($link->event_id, $input, $key);
        $new = $this->input($link->event_id, ['original_document_id' => $first->cutover_original_document_id]); unset($new['file']);
        $second = $this->save($link->event_id, $new);
        $this->assertSame(2, $second->version); $this->assertDatabaseCount('cutover_original_documents', 1);
        $this->assertSame($first->id, $this->save($link->event_id, $input, $key)->id);
        try { $this->save($link->event_id, $input); $this->fail('Stale review replaced new head'); } catch (RecordWriteRejected) {}
        $input['owner_note'] = 'Different request under the same UUID is not allowed.';
        $this->expectException(RecordWriteRejected::class); $this->save($link->event_id, $input, $key);
    }

    public function test_source_day_only_due_date_and_active_honeybook_links_remain_explicit_missing_items(): void
    {
        $link = $this->confirmed();
        $this->save($link->event_id, $this->input($link->event_id, ['crm_balance_due_at' => null, 'external_collections_retired' => false, 'honeybook_automations_disabled' => false]));
        $view = $this->read(); $this->assertFalse($view['readiness']['evidence_ready']);
        foreach (['source_due_time_review_required', 'external_collection_not_retired', 'honeybook_automations_active'] as $code) $this->assertContains($code, $view['readiness']['missing']);
        $this->assertSame('2026-12-19', \App\Models\ImportedProjectRevision::find($link->current_revision_id)->normalized['balance_due_date']);
        $this->expectException(RecordWriteRejected::class); $this->prepare($view);
    }

    public function test_fully_paid_source_needs_no_fabricated_deadline_and_source_unsigned_flag_is_preserved(): void
    {
        $link = $this->confirmed('CUTOVER-FAKE-1', ['amount_paid' => '120.00', 'balance' => '0.00', 'payments' => '2026-10-01|120.00|Cash', 'balance_due_date' => '', 'contract_signed' => 'no']);
        $this->save($link->event_id, $this->input($link->event_id, ['crm_balance_due_at' => null]));
        $read = app(ReadHoneyBookCutoverReview::class)->execute($this->owner, $link->event_id);
        $this->assertFalse($read['source']['contract_signed']); $this->assertTrue($read['document']['owner_attests_signed_original']);
        $this->assertNull($read['review']['crm_balance_due_at']); $this->assertTrue($this->read()['readiness']['evidence_ready']);
        $this->assertDatabaseCount('signature_evidence', 0);
    }

    public function test_invalid_pdf_future_or_ambiguous_observation_and_forged_amounts_are_refused(): void
    {
        $link = $this->confirmed(); $base = $this->input($link->event_id);
        foreach ([['file' => UploadedFile::fake()->createWithContent('fake.pdf', '<script>not a PDF</script>')], ['source_observed_at' => now()->addMinute()->toIso8601String()],
            ['source_observed_at' => '2026-10-17T07:00:00'], ['total_cents' => 1], ['owner_attests_signed_original' => false]] as $change) {
            try { $this->save($link->event_id, [...$base, ...$change]); $this->fail('Invalid private review accepted'); } catch (ValidationException) {}
        }
        $this->assertDatabaseCount('cutover_original_documents', 0); $this->assertDatabaseCount('honeybook_cutover_reviews', 0);
    }

    public function test_corrupt_original_and_encrypted_review_payload_fail_closed(): void
    {
        $link = $this->confirmed(); $review = $this->save($link->event_id);
        DB::table('cutover_original_documents')->where('id', $review->cutover_original_document_id)->update(['document_ciphertext' => 'corrupted fixture cipher']);
        DB::table('honeybook_cutover_reviews')->where('id', $review->id)->update(['payload' => 'corrupted fixture review']);
        $view = $this->read(); $this->assertContains('document_integrity_invalid', $view['readiness']['missing']);
        $this->assertContains('review_stale', $view['readiness']['missing']);
        $this->expectException(RecordWriteRejected::class); $this->prepare($view);
    }

    public function test_manual_responsibility_flip_or_missing_policy_never_becomes_ready(): void
    {
        $link = $this->confirmed(); $this->save($link->event_id);
        Event::whereKey($link->event_id)->update(['responsible_system' => 'crm']); config(['waoo.cutover_reconciliation_max_age_minutes' => 999]);
        $view = $this->read();
        foreach (['responsibility_changed', 'freshness_policy_required', 'financial_bridge_not_implemented', 'transfer_not_implemented'] as $code) $this->assertContains($code, $view['readiness']['missing']);
        $this->assertFalse($view['readiness']['ready']); $this->assertFalse($view['transfer_available']);
    }

    public function test_audit_failure_rolls_back_document_review_and_receipt(): void
    {
        $link = $this->confirmed(); $input = $this->input($link->event_id); $count = DB::table('mutation_receipts')->count(); $fail = true;
        DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$fail) { if ($fail && str_contains($sql, 'audit_entries') && in_array('cutover.event_reviewed', $bindings, true)) throw new \RuntimeException('Synthetic cutover audit failure'); });
        try { $this->save($link->event_id, $input); $this->fail('Expected atomic rollback'); } catch (\RuntimeException $e) { $this->assertSame('Synthetic cutover audit failure', $e->getMessage()); } finally { $fail = false; }
        $this->assertSame($count, DB::table('mutation_receipts')->count()); $this->assertDatabaseCount('cutover_original_documents', 0); $this->assertDatabaseCount('honeybook_cutover_reviews', 0);
    }

    public function test_provider_uncertainty_active_portal_and_unscoped_tip_reconciliation_block_preparation(): void
    {
        $link = $this->confirmed(); $this->save($link->event_id);
        PortalGrant::create(['event_id' => $link->event_id, 'customer_id' => $link->customer_id, 'issued_by' => $this->owner->id, 'token_hash' => hash('sha256', 'synthetic-unused-token'), 'scopes' => ['checkout.create'], 'expires_at' => now()->addHour()]);
        DB::table('tip_reconciliations')->insert(['dedupe_sha256' => hash('sha256', 'synthetic-unallocated-tip'), 'reason' => 'unknown allocation', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $view = $this->read();
        foreach (['review_stale', 'crm_collection_in_flight', 'tip_collection_or_reconciliation_open'] as $code) $this->assertContains($code, $view['readiness']['missing']);
        $this->expectException(RecordWriteRejected::class); $this->prepare($view);
    }

    public function test_http_is_owner_only_no_store_and_independent_feature_flag_is_required(): void
    {
        $link = $this->confirmed(); $base = '/admin/api/cutover/honeybook';
        $r = $this->getJson($base)->assertOk()->assertJsonPath('transfer_available', false)->assertDontSee('fixture-source@example.test');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->getJson($base.'/events/'.$link->event_id)->assertOk()->assertJsonPath('expected_version', 0);
        $this->getJson($base.'/events/999999')->assertNotFound();
        config(['waoo.sandbox_cutover' => false]); $this->getJson($base)->assertForbidden();
        config(['waoo.sandbox_cutover' => true]); $this->owner->forceFill(['active' => false])->save(); $this->getJson($base)->assertForbidden();
    }

    public function test_populated_originals_refuse_destructive_rollback_and_models_are_immutable(): void
    {
        $link = $this->confirmed(); $review = $this->save($link->event_id);
        $migration = require database_path('migrations/2026_10_19_430000_create_cutover_preparation_evidence.php');
        try { $migration->down(); $this->fail('Original evidence deleted by rollback'); } catch (\RuntimeException $e) { $this->assertStringContainsString('forward migration', $e->getMessage()); }
        $this->assertDatabaseCount('cutover_original_documents', 1);
        $this->expectException(\LogicException::class); $review->delete();
    }

    public function test_two_owners_prepare_one_complete_snapshot_without_duplicate_evidence(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('MariaDB pcntl required');
        $link = $this->confirmed(); $this->save($link->event_id); $view = $this->read();
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $worker = fn ($actor) => fn () => app(PrepareHoneyBookCutoverReconciliation::class)->execute($actor, ['expected_cohort_sha256' => $view['cohort_sha256'], 'source_scope_reviewed' => true], (string)Str::uuid())->id;
        $results = ConcurrentWorkers::run([$worker($this->owner), $worker($other)]);
        $this->assertSame([CutoverReconciliation::sole()->id, CutoverReconciliation::sole()->id], $results);
        $this->assertDatabaseCount('cutover_reconciliations', 1); $this->assertDatabaseCount('honeybook_cutover_reviews', 1);
    }

    public function test_money_resync_invalidates_review_but_original_can_be_reused_with_a_new_explicit_review(): void
    {
        $link = $this->confirmed(); $old = $this->save($link->event_id); $snapshot = $this->prepare();
        $batch = $this->stageFuture(['honeybook_project_id' => 'CUTOVER-FAKE-1', 'amount_paid' => '90.00', 'balance' => '30.00', 'payments' => '2026-10-01|90.00|Cash']);
        $row = ImportRow::where('import_batch_id', $batch->id)->sole();
        $diff = app(\App\Services\Imports\ReadHoneyBookProjectDiff::class)->execute($this->owner, $batch->id, $row->id); $c = $diff['current'];
        app(CommitHoneyBookEvent::class)->execute($this->owner, $batch->id, $row->id, ['expected_batch_version' => $batch->version,
            'expected_row_version' => $row->version, 'expected_preview_sha256' => $row->preview_sha256, 'resync' => ['expected_link_version' => $c['link_version'],
                'expected_revision_id' => $c['revision_id'], 'expected_event_version' => $c['event_version'], 'expected_customer_version' => $c['customer_version'],
                'expected_diff_sha256' => $diff['diff_sha256'], 'owner_money_reviewed' => true, 'owner_note' => 'Synthetic source update reviewed.']], (string)Str::uuid());
        $stale = $this->read(); $this->assertContains('review_stale', $stale['readiness']['missing']); $this->assertFalse($stale['reconciliation']['current']);
        $next = $this->input($link->event_id, ['original_document_id' => $old->cutover_original_document_id]); unset($next['file']);
        $this->save($link->event_id, $next); $new = $this->prepare();
        $this->assertNotSame($snapshot->id, $new->id); $this->assertDatabaseCount('cutover_original_documents', 1);
        $this->assertSame(9000, $this->read()['totals']['net_paid_cents']);
        $this->assertSame(2, Event::find($link->event_id)->version); $this->assertDatabaseCount('resource_reservations', 2);
    }

    public function test_cannot_refresh_observation_or_snapshot_expiry_by_tampering_with_clear_metadata(): void
    {
        $link = $this->confirmed(); $review = $this->save($link->event_id); $snapshot = $this->prepare();
        DB::table('honeybook_cutover_reviews')->where('id', $review->id)->update(['source_observed_at' => now()->addHour()]);
        DB::table('cutover_reconciliations')->where('id', $snapshot->id)->update(['expires_at' => now()->addDay()]);
        $read = $this->read(); $this->assertFalse($read['reconciliation']['current']);
        $this->assertContains('review_stale', $read['readiness']['missing']);
        $this->assertContains('reconciliation_integrity_invalid', $read['readiness']['missing']);
        $this->expectException(RecordWriteRejected::class); $this->prepare($read);
    }

    public function test_owner_cannot_reuse_another_events_original_pdf(): void
    {
        $first = $this->confirmed(); $review = $this->save($first->event_id); $other = $this->confirmed('CUTOVER-FAKE-2');
        $input = $this->input($other->event_id, ['original_document_id' => $review->cutover_original_document_id]); unset($input['file']);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class); $this->save($other->event_id, $input);
    }

    public function test_empty_migration_roundtrip_preserves_existing_operational_and_source_records(): void
    {
        $link = $this->confirmed(); $migration = require database_path('migrations/2026_10_19_430000_create_cutover_preparation_evidence.php');
        // Empty dependent tables must be rolled back first, just as the migrator does.
        $opening = require database_path('migrations/2026_10_19_460000_create_cutover_opening_agreements.php');
        $receipts = require database_path('migrations/2026_10_19_470000_create_opening_receipt_bridge.php');
        $sourcePortal = require database_path('migrations/2026_10_19_480000_bind_original_agreement_portal_grants.php');
        $sourcePortal->down();
        $receipts->down();
        $opening->down();
        $migration->down(); $migration->up();
        $opening->up();
        $receipts->up();
        $sourcePortal->up();
        $this->assertSame('honeybook', Event::find($link->event_id)->responsible_system);
        $this->assertDatabaseCount('resource_reservations', 2); $this->assertDatabaseCount('imported_project_revisions', 1);
        $this->assertDatabaseCount('cutover_original_documents', 0);
    }

    public function test_preparation_audit_failure_rolls_back_snapshot_and_receipt(): void
    {
        $link = $this->confirmed(); $this->save($link->event_id); $view = $this->read(); $count = DB::table('mutation_receipts')->count(); $fail = true;
        DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$fail) { if ($fail && str_contains($sql, 'audit_entries') && in_array('cutover.evidence_prepared', $bindings, true)) throw new \RuntimeException('Synthetic preparation audit failure'); });
        try { $this->prepare($view); $this->fail('Expected snapshot rollback'); } catch (\RuntimeException $e) { $this->assertSame('Synthetic preparation audit failure', $e->getMessage()); } finally { $fail = false; }
        $this->assertDatabaseCount('cutover_reconciliations', 0); $this->assertSame($count, DB::table('mutation_receipts')->count());
    }

    public function test_two_owner_reviews_cannot_replace_each_other_from_one_displayed_version(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('MariaDB pcntl required');
        $link = $this->confirmed(); $input = $this->input($link->event_id);
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $worker = fn ($actor) => function () use ($actor, $link, $input) {
            try { app(SaveHoneyBookCutoverReview::class)->execute($actor, $link->event_id, $input, (string)Str::uuid()); return 'saved'; }
            catch (RecordWriteRejected $e) { return $e->status; }
        };
        $results = ConcurrentWorkers::run([$worker($this->owner), $worker($other)]);
        $this->assertContains('saved', $results); $this->assertContains(409, $results, json_encode($results));
        $this->assertDatabaseCount('honeybook_cutover_reviews', 1); $this->assertDatabaseCount('cutover_original_documents', 1);
    }

    public function test_unimported_future_rows_and_unreviewed_source_changes_cannot_disappear_from_scope(): void
    {
        $link = $this->confirmed(); $this->save($link->event_id);
        $extra = $this->stageFuture(['honeybook_project_id' => 'UNIMPORTED-FUTURE']);
        $incomplete = $this->read(); $this->assertFalse($incomplete['readiness']['evidence_ready']);
        $this->assertSame(1, $incomplete['counts']['pending_source_rows']);
        $this->assertContains('source_scope_incomplete', $incomplete['readiness']['missing']);
        app(\App\Services\Imports\DiscardHoneyBookImport::class)->execute($this->owner, $extra->id, ['expected_version' => $extra->version], (string)Str::uuid());
        $this->assertTrue($this->read()['readiness']['evidence_ready']);
        $this->stageFuture(['honeybook_project_id' => 'CUTOVER-FAKE-1', 'status' => 'cancelled']);
        $pending = $this->read(); $this->assertContains('source_scope_unresolved', $pending['readiness']['missing']);
        $this->expectException(RecordWriteRejected::class); $this->prepare($pending);
    }

    public function test_scope_attestation_is_explicit_and_browser_cannot_select_a_smaller_cohort(): void
    {
        $link = $this->confirmed(); $this->save($link->event_id); $v = $this->read();
        foreach ([['expected_cohort_sha256' => $v['cohort_sha256']], ['expected_cohort_sha256' => $v['cohort_sha256'], 'source_scope_reviewed' => false],
            ['expected_cohort_sha256' => $v['cohort_sha256'], 'source_scope_reviewed' => true, 'event_ids' => []]] as $input) {
            try { app(PrepareHoneyBookCutoverReconciliation::class)->execute($this->owner, $input, (string)Str::uuid()); $this->fail('Implicit scope or chosen subset accepted'); }
            catch (ValidationException) {}
        }
        $this->assertDatabaseCount('cutover_reconciliations', 0);
    }

    public function test_late_import_between_preflight_and_locks_invalidates_membership_in_a_real_second_connection(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') $this->markTestSkipped('Independent MariaDB writer required');
        $link = $this->confirmed(); $this->save($link->event_id); $view = $this->read();
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        config(['database.connections.cutover_writer' => config('database.connections.'.DB::getDefaultConnection())]); $armed = true;
        DB::connection()->beforeExecuting(function ($sql) use (&$armed, $other) {
            if ($armed && str_contains($sql, 'import_staging_locks')) {
                $armed = false;
                DB::usingConnection('cutover_writer', function () use ($other) {
                    $batch = app(\App\Services\Imports\StageHoneyBookFutureEvents::class)->execute($other, $this->futureFile([$this->futureRow(['honeybook_project_id' => 'LATE-COHORT-MEMBER'])]), (string)Str::uuid());
                    $row = ImportRow::where('import_batch_id', $batch->id)->sole();
                    app(CommitHoneyBookEvent::class)->execute($other, $batch->id, $row->id, $this->commitInput($batch, $row), (string)Str::uuid());
                });
            }
        });
        try {
            try { $this->prepare($view); $this->fail('Late member omitted from frozen scope'); } catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
            $this->assertFalse($armed); $this->assertDatabaseCount('imported_project_links', 2); $this->assertDatabaseCount('cutover_reconciliations', 0);
        } finally { DB::purge('cutover_writer'); }
    }

    public function test_unknown_checkout_and_delivery_outcomes_require_reconciliation_without_provider_calls(): void
    {
        $link = $this->confirmed(); $this->save($link->event_id);
        $finance = FinancialRevision::create(['event_id' => $link->event_id, 'revision' => 1, 'event_version' => 2, 'currency' => 'USD', 'lines_snapshot' => [],
            'subtotal_cents' => 9500, 'travel_fee_cents' => 2500, 'tax_cents' => 0, 'customer_fee_cents' => 0, 'total_cents' => 12000, 'deposit_target_cents' => 6000,
            'balance_due_at' => now()->addDay(), 'created_by' => $this->owner->id]);
        \App\Models\PaymentIntent::create(['id' => (string)Str::uuid(), 'event_id' => $link->event_id, 'financial_revision_id' => $finance->id,
            'purpose' => 'balance', 'principal_cents' => 6000, 'payable_cents' => 6000, 'status' => 'provider_unknown_state']);
        $mail = \App\Models\OutboundMessage::create(['event_id' => $link->event_id, 'customer_id' => $link->customer_id, 'financial_revision_id' => $finance->id,
            'created_by' => $this->owner->id, 'event_version' => 2, 'customer_version' => 1, 'template_key' => 'synthetic', 'template_version' => 'fixture-v1',
            'locale' => 'es', 'template_sha256' => hash('sha256', 'fixture-template'), 'recipient_sha256' => hash('sha256', 'fixture-recipient'),
            'intent_sha256' => hash('sha256', 'fixture-intent'), 'source_sha256' => hash('sha256', 'fixture-source'), 'due_at' => now(), 'payload' => ['fixture' => true]]);
        \App\Models\OutboundMessageState::create(['outbound_message_id' => $mail->id, 'status' => 'delivery_unknown']);
        $view = $this->read();
        $this->assertContains('crm_collection_in_flight', $view['readiness']['missing']); $this->assertContains('message_outcome_unknown', $view['readiness']['missing']);
        $this->assertFalse($view['readiness']['evidence_ready']); $this->assertDatabaseCount('ledger_entries', 0); Queue::assertNothingPushed();
    }

    public function test_real_owner_http_upload_and_prepare_return_metadata_only_and_keep_transfer_unavailable(): void
    {
        $link = $this->confirmed(); $url = '/admin/api/cutover/honeybook/events/'.$link->event_id;
        $this->withHeader('Idempotency-Key', (string)Str::uuid())->post($url, $this->input($link->event_id), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('review.version', 1)->assertDontSee('PRIVATE_REVIEW_NOTE')->assertDontSee('FICTIONAL_ORIGINAL_ONLY');
        $read = $this->getJson('/admin/api/cutover/honeybook')->assertOk()->assertJsonPath('readiness.evidence_ready', true)->json();
        $this->withHeader('Idempotency-Key', (string)Str::uuid())->postJson('/admin/api/cutover/reconciliations', ['expected_cohort_sha256' => $read['cohort_sha256'], 'source_scope_reviewed' => true])
            ->assertOk()->assertJsonPath('transfer_available', false)->assertJsonPath('sending_enabled', false)->assertDontSee('members')->assertDontSee('PRIVATE_');
        $this->getJson($url.'/documents/1')->assertNotFound();
    }

    public function test_secure_staging_and_production_gates_cannot_be_bypassed_with_feature_flags(): void
    {
        $this->confirmed(); $this->app['env'] = 'staging'; config(['session.secure' => false]);
        $this->getJson('/admin/api/cutover/honeybook')->assertForbidden();
        $csrf = Str::random(40); $this->withSession(['_token' => $csrf])->withHeader('X-CSRF-TOKEN', $csrf);
        $this->withHeader('Idempotency-Key', (string)Str::uuid())->postJson('/admin/api/cutover/reconciliations', ['expected_cohort_sha256' => str_repeat('a', 64), 'source_scope_reviewed' => true])->assertForbidden();
        $this->app['env'] = 'production'; $this->getJson('/admin/api/cutover/honeybook')->assertForbidden();
    }
}
