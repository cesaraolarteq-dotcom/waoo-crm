<?php

namespace Tests\Feature;

use App\Models\{Event, ImportRow, ImportedProjectLink, User};
use App\Services\Imports\{CommitHoneyBookEvent, ReadHoneyBookCutoverMembers, ReadHoneyBookCutoverReadiness, StageHoneyBookFutureEvents};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Mail, Queue, Schema};
use Illuminate\Support\Str;
use Tests\Support\EffectiveOpeningScenario;
use Tests\TestCase;

class CutoverMembersReadTest extends TestCase
{
    use DatabaseMigrations, EffectiveOpeningScenario;

    private const URL = '/admin/api/cutover/honeybook/members';

    protected function setUp(): void
    {
        parent::setUp(); $this->futureScenario(); config(['waoo.sandbox_cutover' => true]);
        Http::preventStrayRequests(); Mail::fake();
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            $this->app->detectEnvironment(fn () => 'testing');
            foreach (['cutover_reconciliations', 'honeybook_cutover_reviews', 'cutover_original_documents'] as $t) if (Schema::hasTable($t)) DB::table($t)->delete();
            if (Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        }
        parent::tearDown();
    }

    private function draft(string $key): ImportedProjectLink
    {
        $batch = $this->stageFuture(['honeybook_project_id' => $key]); $row = ImportRow::where('import_batch_id', $batch->id)->sole();
        return app(CommitHoneyBookEvent::class)->execute($this->owner, $batch->id, $row->id, $this->commitInput($batch, $row), (string)Str::uuid());
    }

    private function read(array $filters = []): array { return app(ReadHoneyBookCutoverMembers::class)->execute($this->owner, $filters); }

    public function test_empty_group_is_a_private_bounded_read_and_cannot_enable_transfer(): void
    {
        $r = $this->getJson(self::URL)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('page.number', 1)->assertJsonPath('page.last', 1)->assertJsonPath('page.total', 0)->assertJsonPath('rows', [])
            ->assertJsonPath('readiness.ready', false)->assertJsonPath('transfer_available', false)->assertJsonPath('sending_enabled', false);
        $this->assertContains('cohort_empty', $r->json('readiness.missing'));
        foreach (['audit_entries', 'cutover_reconciliations', 'ledger_entries', 'outbound_messages', 'portal_grants'] as $t) $this->assertDatabaseCount($t, 0);
        Http::assertNothingSent(); Mail::assertNothingSent(); Queue::assertNothingPushed();
    }

    public function test_pages_keep_complete_group_totals_and_one_protocol_instead_of_one_scan_per_party(): void
    {
        $links = [$this->draft('PAGE-ONE'), $this->draft('PAGE-TWO'), $this->draft('PAGE-THREE')];
        DB::enableQueryLog();
        try {
            $one = $this->read(['page' => 1, 'per_page' => 1]);
            $locks = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'import_staging_locks'));
            $this->assertCount(1, $locks, 'A paginated render must not run one complete cohort protocol per member.');
        } finally { DB::disableQueryLog(); DB::flushQueryLog(); }
        $this->assertSame(3, $one['counts']['events']); $this->assertSame(3, $one['page']['total']); $this->assertSame(36000, $one['totals']['total_cents']);
        $this->assertSame(18000, $one['totals']['net_paid_cents']); $this->assertSame([$links[0]->event_id], array_column($one['rows'], 'event_id'));
        $two = $this->read(['page' => 2, 'per_page' => 1, 'expected_cohort_sha256' => $one['cohort_sha256']]);
        $last = $this->read(['page' => 99999, 'per_page' => 1, 'expected_cohort_sha256' => $one['cohort_sha256']]);
        $this->assertSame([$links[1]->event_id], array_column($two['rows'], 'event_id')); $this->assertSame(3, $last['page']['number']);
        $this->assertSame([$links[2]->event_id], array_column($last['rows'], 'event_id'));
        foreach ([$two, $last] as $page) foreach (['cohort_sha256', 'counts', 'totals', 'unknowns', 'readiness', 'reconciliation'] as $f) $this->assertSame($one[$f], $page[$f]);
        $this->assertSame(app(ReadHoneyBookCutoverReadiness::class)->execute($this->owner), array_diff_key($one, array_flip(['page', 'rows'])));
    }

    public function test_off_page_invalid_source_and_unimported_rows_still_block_the_whole_group(): void
    {
        $this->draft('KNOWN-ONE'); $two = $this->draft('KNOWN-TWO');
        DB::table('imported_project_revisions')->where('id', $two->current_revision_id)->update(['source_sha256' => str_repeat('0', 64)]);
        $this->stageFuture(['honeybook_project_id' => 'STAGED-ONLY']);
        $one = $this->read(['per_page' => 1]);
        $this->assertSame(12000, $one['rows'][0]['source']['total_cents']); $this->assertNull($one['totals']['total_cents']);
        // The tampered known row is unresolved too, in addition to the new unimported row.
        $this->assertSame(1, $one['unknowns']); $this->assertSame(2, $one['counts']['pending_source_rows']);
        $this->assertContains('source_scope_unresolved', $one['readiness']['missing']);
        $this->assertContains('source_integrity_invalid', $one['readiness']['missing']); $this->assertContains('source_scope_incomplete', $one['readiness']['missing']);
        $two = $this->read(['page' => 2, 'per_page' => 1]); $this->assertNull($two['rows'][0]['source']['total_cents']);
        $this->assertFalse($two['rows'][0]['source']['funds_verified']); $this->assertFalse($two['transfer_available']);
    }

    public function test_an_off_page_event_change_refuses_the_old_hash_without_partial_rows(): void
    {
        $this->draft('FIRST'); $other = $this->draft('OTHER'); $one = $this->read(['per_page' => 1]);
        Event::whereKey($other->event_id)->increment('version');
        $this->getJson(self::URL.'?page=2&per_page=1&expected_cohort_sha256='.$one['cohort_sha256'])
            ->assertConflict()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('code', 'cutover_cohort_changed')
            ->assertJsonMissingPath('rows')->assertDontSee('Fictional Source Party');
        $fresh = $this->read(['per_page' => 1]); $this->assertNotSame($one['cohort_sha256'], $fresh['cohort_sha256']);
        $this->assertSame(2, $fresh['counts']['events']);
    }

    public function test_new_import_or_staged_scope_change_invalidates_pagination_even_when_the_first_party_did_not_change(): void
    {
        $this->draft('FIRST'); $before = $this->read(); $this->draft('SECOND');
        $this->getJson(self::URL.'?expected_cohort_sha256='.$before['cohort_sha256'])->assertConflict()->assertJsonPath('code', 'cutover_cohort_changed');
        $before = $this->read(); $this->stageFuture(['honeybook_project_id' => 'NOT-IMPORTED']);
        $this->getJson(self::URL.'?expected_cohort_sha256='.$before['cohort_sha256'])->assertConflict()->assertJsonPath('code', 'cutover_cohort_changed');
        $this->assertSame(2, $this->read()['page']['total']);
    }

    public function test_expiry_is_current_without_renewing_or_treating_the_hash_as_consent(): void
    {
        $this->openingMember(); $first = $this->read(); $this->assertTrue($first['readiness']['evidence_ready']);
        $this->travel(240)->minutes(); $later = $this->read(['expected_cohort_sha256' => $first['cohort_sha256']]);
        $this->assertSame($first['cohort_sha256'], $later['cohort_sha256']); $this->assertFalse($later['readiness']['evidence_ready']);
        $this->assertContains('source_reconciliation_stale', $later['rows'][0]['missing']);
        $this->assertSame($first['rows'][0]['review']['expires_at'], $later['rows'][0]['review']['expires_at']);
        $this->assertDatabaseCount('cutover_reconciliations', 0); $this->assertDatabaseCount('cutover_transfers', 0);
    }

    public function test_private_projection_excludes_source_contacts_notes_payment_details_documents_and_staff(): void
    {
        $this->openingMember();
        $before = [];
        foreach (['events', 'customers', 'resource_reservations', 'booking_evidence', 'honeybook_cutover_reviews', 'cutover_original_documents', 'audit_entries'] as $t) $before[$t] = DB::table($t)->get()->toJson();
        $r = $this->getJson(self::URL)->assertOk()->assertJsonPath('rows.0.review.current', true)->assertJsonPath('rows.0.source.funds_verified', false);
        foreach (['PRIVATE_', 'fixture-source@example.test', '+13465550100', '123 Fictional Venue', 'FICTIONAL_ORIGINAL', 'customer_id', 'document_sha256', 'byte_count', 'token_hash', 'staff_assignment', 'resource_id', 'owner_note', 'source_reference', 'project_key'] as $secret) $r->assertDontSee($secret);
        $this->assertSame(['event_id', 'title', 'starts_at', 'ends_at', 'timezone', 'status', 'responsible_system', 'source', 'missing', 'review'], array_keys($r->json('rows.0')));
        foreach ($before as $t => $bytes) $this->assertSame($bytes, DB::table($t)->get()->toJson(), $t);
        Http::assertNothingSent(); Mail::assertNothingSent(); Queue::assertNothingPushed();
    }

    public function test_closed_filters_cannot_select_a_smaller_cohort_or_request_unbounded_pages(): void
    {
        foreach (['page=0', 'per_page=0', 'per_page=51', 'page[]=1', 'expected_cohort_sha256=bad', 'expected_cohort_sha256=', 'event_ids[]=1', 'active_only=1', 'starts_after=2026-10-19'] as $query) {
            $this->getJson(self::URL.'?'.$query)->assertUnprocessable()->assertJsonMissingPath('rows');
        }
    }

    public function test_owner_two_factor_feature_environment_and_secure_transport_gates_apply(): void
    {
        auth()->logout(); $this->getJson(self::URL)->assertUnauthorized();
        $other = User::factory()->create(['role' => 'staff']); $this->actingAs($other)->getJson(self::URL)->assertForbidden();
        $this->owner->forceFill(['two_factor_confirmed_at' => null])->save(); $this->actingAs($this->owner)->getJson(self::URL)->assertForbidden();
        $this->owner->forceFill(['two_factor_confirmed_at' => now()])->save();
        foreach (['sandbox_cutover', 'sandbox_imports'] as $flag) { config(['waoo.'.$flag => false]); $this->getJson(self::URL)->assertForbidden(); config(['waoo.'.$flag => true]); }
        $this->app->detectEnvironment(fn () => 'production'); $this->getJson(self::URL)->assertForbidden();
        $this->app->detectEnvironment(fn () => 'staging'); config(['session.secure' => true]);
        $this->getJson('http://localhost'.self::URL)->assertForbidden(); config(['session.secure' => false]);
        $this->getJson('https://localhost'.self::URL)->assertForbidden();
        config(['session.secure' => true]); $this->getJson('https://localhost'.self::URL)->assertOk();
    }

    public function test_real_late_import_between_hint_and_lock_never_yields_a_partial_page(): void
    {
        if (DB::getDriverName() !== 'mysql') $this->markTestSkipped('Independent MariaDB writer required.');
        $this->draft('ORIGINAL'); $before = $this->read();
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        config(['database.connections.members_writer' => config('database.connections.'.DB::getDefaultConnection())]); $armed = true;
        DB::connection()->beforeExecuting(function ($sql) use (&$armed, $other) {
            if ($armed && str_contains($sql, 'import_staging_locks')) {
                $armed = false;
                DB::usingConnection('members_writer', function () use ($other) {
                    $batch = app(StageHoneyBookFutureEvents::class)->execute($other, $this->futureFile([$this->futureRow(['honeybook_project_id' => 'LATE-PARTY'])]), (string)Str::uuid());
                    $row = ImportRow::where('import_batch_id', $batch->id)->sole();
                    app(CommitHoneyBookEvent::class)->execute($other, $batch->id, $row->id, $this->commitInput($batch, $row), (string)Str::uuid());
                });
            }
        });
        try {
            $this->getJson(self::URL.'?per_page=1&expected_cohort_sha256='.$before['cohort_sha256'])->assertConflict()->assertJsonMissingPath('rows');
            $this->assertFalse($armed); $this->assertDatabaseCount('imported_project_links', 2);
            $this->assertSame(2, $this->read()['counts']['events']);
        } finally { DB::purge('members_writer'); }
    }
}
