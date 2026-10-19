<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoryImportBatch, HistoryImportRow, User};
use App\Services\History\{DiscardHistoricalImport, ReadHistoricalImport, ReadHistoricalRow, StageHistoricalImport};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Mail, Queue, Schema};
use Illuminate\Support\Str;
use Tests\Support\{ConcurrentWorkers, HistoricalSourceFixture};
use Tests\TestCase;

class HistoricalIntakeTest extends TestCase
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
            DB::table('history_import_rows')->delete(); DB::table('history_import_batches')->delete();
        }
        if ($this->app) $this->app->detectEnvironment(fn () => 'testing');
        parent::tearDown();
    }
    private function stage(array $rows = [], ?string $key = null): array
    {
        return app(StageHistoricalImport::class)->execute($this->owner, $this->historyFile($rows ?: [$this->historyRow()]), $key ?? (string)Str::uuid());
    }
    private function read(int $id, array $filters = []): array
    {
        return app(ReadHistoricalImport::class)->execute($this->owner, $id, $filters);
    }
    private function discardInput(int $id): array
    {
        $v = $this->read($id);
        return ['expected_batch_version' => $v['batch']['version'], 'expected_coverage_sha256' => $v['coverage_sha256'], 'owner_reason' => 'PRIVATE_OWNER_REASON fixture only'];
    }
    public function test_private_staging_preserves_source_without_any_domain_or_provider_side_effect(): void
    {
        $id = $this->stage()['batch_id'];
        $v = $this->read($id);
        $this->assertSame(1, $v['counts']['rows']);
        $this->assertSame(0, $v['counts']['imported']);
        $this->assertFalse($v['commit_available']);
        $this->assertSame(['state' => 'not_evaluated', 'candidate_count' => null], $v['rows'][0]['customer_match']);
        $this->assertStringNotContainsString('PRIVATE_HISTORY_FIXTURE', json_encode($v));
        $row = app(ReadHistoricalRow::class)->execute($this->owner, $id, $v['rows'][0]['id']);
        $this->assertSame('PRIVATE_HISTORY_FIXTURE', $row['source']['client_name']);
        foreach (['history_import_batches', 'history_import_rows', 'audit_entries', 'mutation_receipts'] as $t) {
            $db = DB::table($t)->get()->toJson();
            foreach (['PRIVATE_HISTORY_FIXTURE', 'PRIVATE_FILENAME', 'history@example.test', '=UNTRUSTED_SOURCE_FORMULA'] as $secret) $this->assertStringNotContainsString($secret, $db, $t);
        }
        foreach (['customers', 'events', 'resource_reservations', 'ledger_entries', 'financial_revisions', 'booking_evidence', 'contracts', 'portal_grants', 'outbound_messages', 'tip_grants'] as $t) $this->assertDatabaseCount($t, 0);
        $this->assertStringNotContainsString('artifact', HistoryImportBatch::sole()->toJson());
        $this->assertStringNotContainsString('payload', HistoryImportRow::sole()->toJson());
        Queue::assertNothingPushed(); Mail::assertNothingSent(); Http::assertNothingSent();
    }
    public function test_stage_retry_and_new_key_same_file_reuse_batch_but_changed_body_conflicts(): void
    {
        $key = (string)Str::uuid();
        $first = $this->stage(key: $key);
        $this->assertSame($first, $this->stage(key: $key));
        $this->assertSame($first, $this->stage());
        $this->assertDatabaseCount('history_import_batches', 1);
        $this->assertDatabaseCount('history_import_rows', 1);
        $this->expectException(RecordWriteRejected::class);
        $this->stage([$this->historyRow(['project_id' => 'different'])], $key);
    }
    public function test_pagination_clamps_and_selected_row_cannot_cross_batches(): void
    {
        $rows = [];
        for ($n = 0; $n < 51; $n++) $rows[] = $this->historyRow(['project_id' => 'fixture-'.$n]);
        $id = $this->stage($rows)['batch_id'];
        $v = $this->read($id, ['page' => 999, 'per_page' => 25]);
        $this->assertSame(['number' => 3, 'last' => 3, 'total' => 51], $v['page']);
        $this->assertCount(1, $v['rows']);
        $other = $this->stage([$this->historyRow(['project_id' => 'other'])])['batch_id'];
        $this->getJson('/admin/api/history/imports/'.$other.'/rows/'.$v['rows'][0]['id'])->assertNotFound();
    }
    public function test_discard_is_atomic_private_and_retry_does_not_restore_content(): void
    {
        $stageKey = (string)Str::uuid();
        $id = $this->stage(key: $stageKey)['batch_id'];
        $input = $this->discardInput($id); $key = (string)Str::uuid();
        $saved = app(DiscardHistoricalImport::class)->execute($this->owner, $id, $input, $key);
        $this->assertSame(['batch_id' => $id, 'version' => 2, 'state' => 'discarded'], $saved);
        $this->assertSame($saved, app(DiscardHistoricalImport::class)->execute($this->owner, $id, $input, $key));
        $this->assertDatabaseCount('history_import_rows', 0);
        $this->assertNull(DB::table('history_import_batches')->sole()->artifact);
        $this->assertStringNotContainsString('PRIVATE_OWNER_REASON', DB::table('audit_entries')->get()->toJson());
        $this->getJson('/admin/api/history/imports/'.$id)->assertGone();
        try { $this->stage(key: $stageKey); $this->fail('Old receipt must not recreate data.'); }
        catch (RecordWriteRejected $e) { $this->assertSame(410, $e->status); }
        $this->assertNotSame($id, $this->stage()['batch_id']);
    }
    public function test_stale_discard_and_audit_failure_leave_all_content_intact(): void
    {
        $id = $this->stage()['batch_id']; $input = $this->discardInput($id);
        try { app(DiscardHistoricalImport::class)->execute($this->owner, $id, [...$input, 'expected_batch_version' => 2], (string)Str::uuid()); $this->fail('Expected stale refusal.'); }
        catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        Schema::rename('audit_entries', 'history_test_audit_off');
        try {
            try { app(DiscardHistoricalImport::class)->execute($this->owner, $id, $input, (string)Str::uuid()); $this->fail('Audit must roll back.'); }
            catch (\RuntimeException $e) { $this->assertSame('Private import persistence unavailable.', $e->getMessage()); }
        } finally { Schema::rename('history_test_audit_off', 'audit_entries'); }
        $this->assertSame(1, $this->read($id)['counts']['rows']);
        $this->assertDatabaseCount('history_import_rows', 1);
        $this->assertDatabaseCount('mutation_receipts', 1);
    }
    public function test_tampered_row_ciphertext_is_a_safe_refusal(): void
    {
        $id = $this->stage()['batch_id'];
        DB::table('history_import_rows')->update(['payload' => 'CORRUPT_PRIVATE_PAYLOAD']);
        $r = $this->getJson('/admin/api/history/imports/'.$id)->assertStatus(409);
        $r->assertDontSee('CORRUPT_PRIVATE_PAYLOAD')->assertDontSee('SQL');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }
    public function test_manifest_metadata_and_cross_row_swaps_are_refused(): void
    {
        $id = $this->stage([$this->historyRow(), $this->historyRow(['project_id' => 'two'])])['batch_id'];
        $rows = DB::table('history_import_rows')->orderBy('id')->get();
        DB::table('history_import_rows')->where('id', $rows[1]->id)->update(['payload' => $rows[0]->payload, 'preview_sha256' => $rows[0]->preview_sha256]);
        $this->getJson('/admin/api/history/imports/'.$id.'/rows/'.$rows[1]->id)->assertStatus(409);
        DB::table('history_import_batches')->where('id', $id)->update(['cutoff' => now()->addDay()]);
        $this->getJson('/admin/api/history/imports/'.$id)->assertStatus(409);
    }
    public function test_http_upload_and_privacy_with_missing_commit_endpoint(): void
    {
        $r = $this->postJson('/admin/api/history/imports', ['file' => $this->historyFile([$this->historyRow()])], ['Idempotency-Key' => (string)Str::uuid()])->assertCreated();
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $id = $r->json('batch_id');
        $this->getJson('/admin/api/history/imports/'.$id)->assertOk()->assertJsonPath('commit_available', false)->assertDontSee('history@example.test');
        $this->postJson('/admin/api/history/imports/'.$id.'/rows/1/commit')->assertNotFound();
        $this->postJson('/admin/api/history/imports/'.$id.'/close')->assertNotFound();
    }
    public function test_feature_role_2fa_and_production_gates_apply_on_every_read_and_replay(): void
    {
        $key = (string)Str::uuid(); $id = $this->stage(key: $key)['batch_id'];
        config(['waoo.sandbox_history' => false]);
        $this->getJson('/admin/api/history/imports/'.$id)->assertForbidden();
        config(['waoo.sandbox_history' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->getJson('/admin/api/history/imports/'.$id)->assertForbidden();
        $this->app->detectEnvironment(fn () => 'testing');
        $this->owner->forceFill(['two_factor_confirmed_at' => null])->save();
        $this->getJson('/admin/api/history/imports/'.$id)->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $this->stage(key: $key);
    }
    public function test_staging_transport_and_csrf_are_required(): void
    {
        $id = $this->stage()['batch_id'];
        $this->app->detectEnvironment(fn () => 'staging');
        config(['session.secure' => true]);
        $this->getJson('http://localhost/admin/api/history/imports/'.$id)->assertForbidden();
        $this->getJson('https://localhost/admin/api/history/imports/'.$id)->assertOk();
        $this->postJson('https://localhost/admin/api/history/imports/'.$id.'/discard', $this->discardInput($id))->assertStatus(419);
    }
    public function test_artifact_corruption_cannot_be_presented_as_valid_evidence(): void
    {
        $id = $this->stage()['batch_id'];
        $batch = HistoryImportBatch::findOrFail($id);
        $batch->artifact = ['batch_id' => $id, 'bytes' => 'PRIVATE_REPLACEMENT']; $batch->save();
        $this->getJson('/admin/api/history/imports/'.$id)->assertStatus(409)->assertDontSee('PRIVATE_REPLACEMENT');
    }
    public function test_stage_audit_failure_rolls_back_all_encrypted_rows_and_receipt(): void
    {
        Schema::rename('audit_entries', 'history_test_audit_off');
        try {
            try { $this->stage(); $this->fail('Audit must roll back stage.'); }
            catch (\RuntimeException $e) { $this->assertSame('Private import persistence unavailable.', $e->getMessage()); }
        } finally { Schema::rename('history_test_audit_off', 'audit_entries'); }
        $this->assertDatabaseCount('history_import_batches', 0);
        $this->assertDatabaseCount('history_import_rows', 0);
        $this->assertDatabaseCount('mutation_receipts', 0);
    }
    public function test_two_owners_stage_identical_file_into_one_private_batch(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('MariaDB and pcntl required.');
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $owner = $this->owner;
        $results = ConcurrentWorkers::run([
            fn () => app(StageHistoricalImport::class)->execute($owner, $this->historyFile([$this->historyRow()]), (string)Str::uuid()),
            fn () => app(StageHistoricalImport::class)->execute($other, $this->historyFile([$this->historyRow()]), (string)Str::uuid()),
        ]);
        $this->assertIsArray($results[0]); $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('history_import_batches', 1);
        $this->assertDatabaseCount('history_import_rows', 1);
        $this->assertDatabaseCount('mutation_receipts', 2);
    }
    public function test_populated_rollback_refuses_to_destroy_private_evidence(): void
    {
        $this->stage();
        $migration = require database_path('migrations/2026_10_19_440000_create_history_intake.php');
        try { $migration->down(); $this->fail('Populated rollback must refuse.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('forward migration', $e->getMessage()); }
        $this->assertDatabaseCount('history_import_batches', 1);
        DB::table('history_import_rows')->delete(); DB::table('history_import_batches')->delete();
        $migration->down(); $migration->up();
        $this->assertTrue(Schema::hasTable('history_import_rows'));
    }
}
