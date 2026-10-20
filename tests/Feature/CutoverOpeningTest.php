<?php
namespace Tests\Feature;

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

class CutoverOpeningTest extends TestCase
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
    private function input(CutoverReconciliation $r): array { return ['reconciliation_id' => $r->id, 'expected_cohort_sha256' => $r->cohort_sha256]; }
    private function prepare(CutoverReconciliation $r, ?string $key = null): array
    {
        return app(PrepareCutoverOpeningAgreements::class)->execute($this->owner, $this->input($r), $key ?? (string)Str::uuid());
    }
    private function read(int $id): array { return app(ReadCutoverOpeningAgreements::class)->execute($this->owner, $id); }

    public function test_opening_preserves_source_gross_refunds_net_actual_version_and_no_operating_effect(): void
    {
        $link = $this->member(); $r = $this->reconciliation(); $before = [];
        foreach (['events', 'event_services', 'customers', 'booking_evidence', 'resource_reservations', 'imported_project_revisions', 'imported_ledger_lines', 'operational_reviews'] as $t) $before[$t] = DB::table($t)->get()->toJson();
        $finance = app(FinanceSummary::class)->forEvent(Event::find($link->event_id));
        $period = ['period' => 'month', 'anchor' => '2026-10-17'];
        $sales = app(\App\Services\Reports\BookingSalesReport::class)->execute($this->owner, $period);
        $report = app(\App\Services\Reports\OperationalReport::class)->execute($this->owner, $period);
        $result = $this->prepare($r); $v = $this->read($result['opening_set_id']); $i = $v['items'][0];
        $this->assertTrue($v['current']); $this->assertSame('preparation_only', $v['authority']); $this->assertNull($v['effective_at']);
        $this->assertSame([12000, 7000, 1000, 6000, 6000, 0], array_map(fn ($k) => $i[$k], ['original_total_cents', 'source_gross_received_cents', 'source_refund_cents', 'source_net_received_cents', 'balance_cents', 'credit_cents']));
        $this->assertSame(Event::find($link->event_id)->version, $i['event_version']);
        $this->assertSame(['new_sales_cents' => 0, 'crm_cash_received_cents' => 0, 'historical_source_net_cents' => 6000], $i['accounting']);
        $this->assertSame('date', $i['source_due_precision']); $this->assertSame('2026-12-19', $i['source_balance_due_date']);
        $this->assertSame('2026-12-19T21:00:00+00:00', $i['crm_balance_due_at']);
        $this->assertFalse($i['funds_verified']); $this->assertFalse($v['transfer_available']);
        foreach ($before as $t => $raw) $this->assertSame($raw, DB::table($t)->get()->toJson(), $t);
        $this->assertSame($finance, app(FinanceSummary::class)->forEvent(Event::find($link->event_id)));
        $this->assertSame($sales, app(\App\Services\Reports\BookingSalesReport::class)->execute($this->owner, $period));
        $this->assertSame($report, app(\App\Services\Reports\OperationalReport::class)->execute($this->owner, $period));
        $this->assertSame(0, $sales['booking_count']); $this->assertSame(1, $sales['imported_confirmation_count_excluded']);
        $this->assertSame(0, $report['cash']['received_cents']);
        try { app(\App\Services\Finance\CreateFinancialRevision::class)->execute($this->owner, $link->event_id,
            ['expected_version' => Event::find($link->event_id)->version], (string)Str::uuid()); $this->fail('Prepared opening bypassed original agreement guard.'); }
        catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        $this->postJson('/admin/api/events/'.$link->event_id.'/payments/zelle', ['financial_revision_id' => 1, 'amount_cents' => 100,
            'currency' => 'USD', 'reference' => 'Fictional bank reference', 'received_at' => now()->subMinute()->toIso8601String(),
            'verified_received' => true], ['Idempotency-Key' => (string)Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'responsible_system_honeybook');
        foreach (['financial_revisions', 'ledger_entries', 'contracts', 'signature_evidence', 'portal_grants', 'outbound_messages', 'tip_grants'] as $t) $this->assertDatabaseCount($t, 0);
        $this->assertSame('honeybook', Event::find($link->event_id)->responsible_system);
        foreach (['PRIVATE_SOURCE_REFERENCE', 'PRIVATE_REVIEW_NOTE', 'PRIVATE_OPERATIONS_NOTE', 'PRIVATE_FILENAME', 'fixture-source@example.test', 'FICTIONAL_ORIGINAL'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($v));
            foreach (['cutover_opening_sets', 'cutover_opening_agreements'] as $t) $this->assertStringNotContainsString($secret, DB::table($t)->get()->toJson());
        }
        Queue::assertNothingPushed(); Mail::assertNothingSent(); Http::assertNothingSent();
    }
    public function test_same_key_and_new_key_reuse_one_whole_group_without_renewing_expiry(): void
    {
        $this->member(); $this->member('OPENING-FAKE-2'); $r = $this->reconciliation(); $key = (string)Str::uuid();
        $first = $this->prepare($r, $key); $this->travel(30)->minutes();
        $this->assertSame($first, $this->prepare($r, $key)); $this->assertSame($first, $this->prepare($r));
        $this->assertDatabaseCount('cutover_opening_sets', 1); $this->assertDatabaseCount('cutover_opening_agreements', 2);
        $this->assertSame($r->expires_at->toIso8601String(), $first['expires_at']);
    }
    public function test_exact_expiry_refuses_new_preparation_but_receipt_is_historical_only(): void
    {
        $this->member(); $r = $this->reconciliation(); $key = (string)Str::uuid(); $first = $this->prepare($r, $key);
        $this->travelTo($r->expires_at);
        $this->assertFalse($this->read($first['opening_set_id'])['current']);
        $this->assertSame($first, $this->prepare($r, $key));
        $this->expectException(RecordWriteRejected::class); $this->prepare($r);
    }
    public function test_new_member_or_changed_source_binding_invalidates_preparation(): void
    {
        $link = $this->member(); $r = $this->reconciliation(); $first = $this->prepare($r);
        DB::table('customers')->where('id', $link->customer_id)->increment('version');
        $this->assertFalse($this->read($first['opening_set_id'])['current']);
        try { $this->prepare($r); $this->fail('Changed binding accepted.'); } catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        $this->member('OPENING-FAKE-2');
        $this->expectException(RecordWriteRejected::class); $this->prepare($r);
    }
    public function test_changed_deposit_operational_evidence_cannot_reuse_an_existing_opening(): void
    {
        $link = $this->member(); $r = $this->reconciliation(); $first = $this->prepare($r);
        $review = OperationalReview::where('event_id', $link->event_id)->sole(); $review->evidence = ['changed' => 'PRIVATE_NEW_EVIDENCE']; $review->save();
        $v = $this->read($first['opening_set_id']);
        $this->assertFalse($v['current']); $this->assertContains('opening_capacity_evidence_changed', $v['missing']);
        $this->expectException(RecordWriteRejected::class); $this->prepare($r);
    }
    public function test_owner_still_using_honeybook_cannot_prepare_an_opening_with_false_retirement_attestations(): void
    {
        $this->member(review: ['honeybook_automations_disabled' => false, 'external_collections_retired' => false]);
        $v = app(ReadHoneyBookCutoverReadiness::class)->execute($this->owner);
        $this->assertFalse($v['readiness']['evidence_ready']);
        $this->expectException(RecordWriteRejected::class); $this->reconciliation();
    }
    public function test_full_paid_unknown_due_and_overpayment_are_not_repriced_or_assigned_new_deadlines(): void
    {
        $this->member(source: ['amount_paid' => '125.00', 'balance' => '-5.00', 'balance_due_date' => '', 'payments' => '2026-10-01|125.00|Cash'], review: ['crm_balance_due_at' => null]);
        $r = $this->reconciliation(); $v = $this->read($this->prepare($r)['opening_set_id'])['items'][0];
        $this->assertSame(0, $v['balance_cents']); $this->assertSame(500, $v['credit_cents']);
        $this->assertNull($v['source_balance_due_date']); $this->assertNull($v['crm_balance_due_at']); $this->assertSame('unknown', $v['source_due_precision']);
    }
    public function test_audit_failure_rolls_back_all_openings_and_preserves_original_reconciliation(): void
    {
        $this->member(); $this->member('OPENING-FAKE-2'); $r = $this->reconciliation();
        Schema::rename('audit_entries', 'opening_test_audit_off');
        try {
            try { $this->prepare($r); $this->fail('Audit failure did not roll back.'); }
            catch (\RuntimeException $e) { $this->assertSame('Private import persistence unavailable.', $e->getMessage()); }
        } finally { Schema::rename('opening_test_audit_off', 'audit_entries'); }
        $this->assertDatabaseCount('cutover_opening_sets', 0); $this->assertDatabaseCount('cutover_opening_agreements', 0);
        $this->assertDatabaseCount('cutover_reconciliations', 1);
        $this->assertSame(0, DB::table('mutation_receipts')->where('result_type', 'cutover_opening_set')->count());
    }
    public function test_tampered_opening_and_changed_clear_expiry_are_rejected_without_leaking_payload(): void
    {
        $this->member(); $r = $this->reconciliation(); $result = $this->prepare($r);
        DB::table('cutover_opening_sets')->update(['expires_at' => now()->addDay()]);
        $this->getJson('/admin/api/cutover/openings/'.$result['opening_set_id'])->assertStatus(409)->assertDontSee('PRIVATE_REVIEW_NOTE');
        DB::table('cutover_opening_sets')->update(['expires_at' => $r->expires_at]);
        DB::table('cutover_opening_agreements')->update(['payload' => 'CORRUPT_PRIVATE_OPENING']);
        $this->getJson('/admin/api/cutover/openings/'.$result['opening_set_id'])->assertStatus(409)->assertDontSee('CORRUPT_PRIVATE_OPENING');
    }
    public function test_accepted_source_difference_is_bound_without_inventing_a_card_fee(): void
    {
        $link = $this->member(source: ['total' => '123.60', 'amount_paid' => '70.00', 'balance' => '53.60', 'payments' => '2026-10-01|70.00|Zelle'],
            commit: ['source_difference_accepted' => true, 'source_difference_note' => 'PRIVATE_ACCEPTED_DIFFERENCE fixture source amount accepted.']);
        $r = $this->reconciliation(); $result = $this->prepare($r);
        $f = CutoverOpeningAgreement::sole()->payload['facts'];
        $revision = \App\Models\ImportedProjectRevision::findOrFail($link->current_revision_id);
        $this->assertSame(12360, $f['original_total_cents']);
        $this->assertSame(\App\Services\Finance\MutationKey::hash($revision->mapping['source_difference']), $f['source_difference_sha256']);
        $this->assertSame(5360, $this->read($result['opening_set_id'])['items'][0]['balance_cents']);
    }
    public function test_closed_input_replay_authorization_and_encrypted_membership_are_enforced(): void
    {
        $this->member(); $this->member('OPENING-FAKE-2'); $r = $this->reconciliation(); $key = (string)Str::uuid(); $result = $this->prepare($r, $key);
        $this->postJson('/admin/api/cutover/openings', [...$this->input($r), 'total_cents' => 1], ['Idempotency-Key' => (string)Str::uuid()])->assertUnprocessable();
        $this->postJson('/admin/api/cutover/openings', [...$this->input($r), 'expected_cohort_sha256' => str_repeat('f', 64)], ['Idempotency-Key' => $key])->assertStatus(409);
        $rows = DB::table('cutover_opening_agreements')->orderBy('id')->get();
        DB::table('cutover_opening_agreements')->where('id', $rows[1]->id)->update(['payload' => $rows[0]->payload, 'payload_sha256' => $rows[0]->payload_sha256]);
        $this->getJson('/admin/api/cutover/openings/'.$result['opening_set_id'])->assertStatus(409);
        $this->owner->forceFill(['two_factor_confirmed_at' => null])->save();
        $this->getJson('/admin/api/cutover/openings/'.$result['opening_set_id'])->assertForbidden();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class); $this->prepare($r, $key);
    }
    public function test_owner_http_feature_production_csrf_gates_and_no_transfer_route(): void
    {
        $this->member(); $r = $this->reconciliation();
        $response = $this->postJson('/admin/api/cutover/openings', $this->input($r), ['Idempotency-Key' => (string)Str::uuid()])->assertOk();
        $id = $response->json('opening_set_id'); $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        config(['waoo.sandbox_cutover_bridge' => false]); $this->getJson('/admin/api/cutover/openings/'.$id)->assertForbidden();
        config(['waoo.sandbox_cutover_bridge' => true]); $this->app->detectEnvironment(fn () => 'production');
        $this->getJson('/admin/api/cutover/openings/'.$id)->assertForbidden();
        $this->app->detectEnvironment(fn () => 'staging'); config(['session.secure' => true]);
        $this->getJson('http://localhost/admin/api/cutover/openings/'.$id)->assertForbidden();
        $this->getJson('https://localhost/admin/api/cutover/openings/'.$id)->assertOk();
        $this->postJson('https://localhost/admin/api/cutover/openings', $this->input($r))->assertStatus(419);
        $this->app->detectEnvironment(fn () => 'testing');
        $this->postJson('/admin/api/cutover/openings/'.$id.'/activate')->assertNotFound();
        $this->postJson('/admin/api/cutover/reconciliations/'.$r->id.'/transfer')->assertNotFound();
    }
    public function test_two_actual_owners_prepare_one_set_for_the_same_reconciliation(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('MariaDB/pcntl required.');
        $this->member(); $r = $this->reconciliation(); $input = $this->input($r); $owner = $this->owner;
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $results = ConcurrentWorkers::run([fn () => app(PrepareCutoverOpeningAgreements::class)->execute($owner, $input, (string)Str::uuid()),
            fn () => app(PrepareCutoverOpeningAgreements::class)->execute($other, $input, (string)Str::uuid())]);
        $this->assertIsArray($results[0]); $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('cutover_opening_sets', 1); $this->assertDatabaseCount('cutover_opening_agreements', 1);
    }
    public function test_populated_down_refuses_destruction_and_empty_roundtrip_works(): void
    {
        $this->member(); $r = $this->reconciliation(); $this->prepare($r);
        $migration = require database_path('migrations/2026_10_19_460000_create_cutover_opening_agreements.php');
        try { $migration->down(); $this->fail('Populated rollback erased evidence.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('forward migration', $e->getMessage()); }
        DB::table('cutover_opening_agreements')->delete(); DB::table('cutover_opening_sets')->delete();
        $receipts = require database_path('migrations/2026_10_19_470000_create_opening_receipt_bridge.php');
        $sourcePortal = require database_path('migrations/2026_10_19_480000_bind_original_agreement_portal_grants.php');
        $sourcePortal->down();
        $receipts->down();
        $migration->down(); $migration->up(); $this->assertTrue(Schema::hasTable('cutover_opening_agreements'));
        $receipts->up();
        $sourcePortal->up();
    }
    public function test_source_amount_outside_declared_technical_bound_is_safe_and_not_repriced(): void
    {
        $this->member(source: ['package_total' => '1999999975.00', 'discount' => '0.00', 'total' => '2000000000.00',
            'amount_paid' => '1000000000.00', 'balance' => '1000000000.00', 'payments' => '2026-10-01|1000000000.00|Zelle']);
        $r = $this->reconciliation();
        $this->postJson('/admin/api/cutover/openings', $this->input($r), ['Idempotency-Key' => (string)Str::uuid()])->assertStatus(409);
        $this->assertDatabaseCount('cutover_opening_sets', 0);
        $this->assertDatabaseCount('cutover_opening_agreements', 0);
        $this->assertSame(200000000000, \App\Models\ImportedProjectRevision::sole()->normalized['total_cents']);
    }
}
