<?php

namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{BookingEvidence, Customer, Event, ImportBatch, ImportFile, ImportedLedgerLine, ImportedProjectLink, ImportedProjectRevision, ImportRow, Resource, ServiceVariant, User};
use App\Services\Imports\{CommitHoneyBookEvent, ConfirmImportedCapacity, FinalizeHoneyBookImport, ImportedAgreement, ReadHoneyBookProjectDiff, ReadImportCoverage};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue, Schema, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\{ConcurrentWorkers, HoneyBookFutureScenario};
use Tests\TestCase;

class HoneyBookMoneyResyncTest extends TestCase
{
    use DatabaseMigrations, HoneyBookFutureScenario;

    protected function setUp(): void { parent::setUp(); $this->futureScenario(); }
    protected function tearDown(): void
    {
        if ($this->app && Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        if ($this->app) $this->app->detectEnvironment(fn () => 'testing');
        parent::tearDown();
    }

    private function confirmed(array $source = [], array $input = []): ImportedProjectLink
    {
        $batch = $this->stageFuture($source);
        $row = ImportRow::where('import_batch_id', $batch->id)->sole();
        $link = app(CommitHoneyBookEvent::class)->execute($this->owner, $batch->id, $row->id, $this->commitInput($batch, $row, $input), (string)Str::uuid());
        $ids = [Resource::create(['code' => 'RESYNC-HOST', 'name' => 'Fictional host', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'driver']])->id,
            Resource::create(['code' => 'RESYNC-SPKR', 'name' => 'Fictional speaker', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []])->id];
        app(ConfirmImportedCapacity::class)->execute($this->owner, $link->event_id, ['expected_version' => Event::findOrFail($link->event_id)->version,
            'imported_revision_id' => $link->current_revision_id, 'resource_ids' => $ids,
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Fictional owner reviewed travel and return.', 'transfers' => []]], (string)Str::uuid());
        return $link->fresh();
    }

    private function update(array $change = []): array
    {
        $batch = $this->stageFuture(['amount_paid' => '90.00', 'balance' => '30.00',
            'payments' => '2026-10-01|70.00|Zelle;2026-10-02|-10.00|source adjustment;2026-10-16|30.00|Cash', ...$change]);
        $row = ImportRow::where('import_batch_id', $batch->id)->sole();
        $view = app(ReadHoneyBookProjectDiff::class)->execute($this->owner, $batch->id, $row->id);
        return [$batch, $row, $view, $this->resyncInput($view)];
    }

    private function resyncInput(array $view): array
    {
        $c = $view['current'];
        return ['expected_batch_version' => $view['batch']['version'], 'expected_row_version' => $view['row']['version'],
            'expected_preview_sha256' => $view['row']['preview_sha256'], 'resync' => ['expected_link_version' => $c['link_version'],
                'expected_revision_id' => $c['revision_id'], 'expected_event_version' => $c['event_version'], 'expected_customer_version' => $c['customer_version'],
                'expected_diff_sha256' => $view['diff_sha256'], 'owner_money_reviewed' => true, 'owner_note' => 'PRIVATE_OWNER_RESYNC_NOTE fixture source checked.',
                ...($view['source_difference'] ? ['retain_source_difference_revision_id' => $c['revision_id']] : [])]];
    }

    private function save($batch, $row, array $input, ?string $key = null): ImportedProjectLink
    {
        return app(CommitHoneyBookEvent::class)->execute($this->owner, $batch->id, $row->id, $input, $key ?? (string)Str::uuid());
    }

    public function test_confirmed_money_update_appends_source_only_and_preserves_all_operational_and_bank_evidence(): void
    {
        $link = $this->confirmed();
        $old = ImportedProjectRevision::findOrFail($link->current_revision_id)->toArray();
        $before = [];
        foreach (['events', 'event_services', 'event_service_characters', 'resource_reservations', 'booking_evidence', 'operational_reviews', 'customers'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        [$batch, $row, $view, $input] = $this->update();
        $this->assertSame('money_only', $view['kind']);
        $this->assertSame(['paid_cents', 'balance_cents', 'payments'], array_column($view['changes'], 'field'));
        $saved = $this->save($batch, $row, $input);
        $this->assertSame(2, $saved->version);
        $this->assertSame($old, ImportedProjectRevision::findOrFail($old['id'])->toArray());
        foreach ($before as $table => $bytes) $this->assertSame($bytes, DB::table($table)->get()->toJson(), $table);
        $this->assertDatabaseCount('imported_project_revisions', 2);
        $this->assertDatabaseCount('imported_ledger_lines', 5);
        $summary = ImportedAgreement::summary($link->event_id);
        $this->assertSame([10000, 1000, 9000, 3000], array_map(fn ($k) => $summary[$k], ['received_cents', 'refunded_cents', 'net_received_cents', 'balance_cents']));
        $this->assertFalse($summary['funds_verified']);
        foreach (['ledger_entries', 'financial_revisions', 'contracts', 'portal_grants', 'outbound_messages'] as $table) $this->assertDatabaseCount($table, 0);
        $this->assertTrue(app(ReadImportCoverage::class)->execute($this->owner, $batch->id)['capacity_complete']);
        $this->assertSame(1, BookingEvidence::sole()->imported_revision_id);
        $audit = json_encode(DB::table('audit_entries')->get());
        foreach (['PRIVATE_OWNER_RESYNC_NOTE', 'PRIVATE_SOURCE_NOTE_FIXTURE', 'fixture-source@example.test'] as $secret) $this->assertStringNotContainsString($secret, $audit);
        Queue::assertNothingPushed();
    }

    public function test_retired_or_repriced_catalog_does_not_rebuild_a_frozen_operational_agreement(): void
    {
        $this->confirmed();
        ServiceVariant::whereKey($this->price->service_variant_id)->update(['status' => 'draft', 'version' => 7, 'requirements' => '[]']);
        [$batch, $row, $view, $input] = $this->update();
        $this->assertSame('money_only', $view['kind']);
        $this->save($batch, $row, $input);
        $this->assertSame(9000, ImportedAgreement::summary(Event::sole()->id)['net_received_cents']);
    }

    public function test_same_key_replay_after_finalization_and_identical_source_new_key_do_not_move_head_or_note(): void
    {
        $this->confirmed(); [$batch, $row, $view, $input] = $this->update(); $key = (string)Str::uuid();
        $saved = $this->save($batch, $row, $input, $key);
        $newView = app(ReadHoneyBookProjectDiff::class)->execute($this->owner, $batch->id, $row->id);
        $this->assertSame('unchanged', $newView['kind']);
        $newInput = $this->resyncInput($newView); $newInput['resync']['owner_note'] = 'Different note must not replace immutable review.';
        $this->save($batch, $row, $newInput);
        $this->assertSame(2, $saved->fresh()->version);
        $this->assertDatabaseCount('imported_project_revisions', 2);
        $this->assertSame($input['resync']['owner_note'], ImportedProjectRevision::findOrFail($saved->current_revision_id)->mapping['money_resync']['owner_note']);
        app(FinalizeHoneyBookImport::class)->execute($this->owner, $batch->id, ['expected_version' => $batch->fresh()->version, 'source_coverage_reviewed' => true], (string)Str::uuid());
        $this->assertSame($saved->id, $this->save($batch, $row, $input, $key)->id);
        $changed = $input; $changed['resync']['owner_note'] = 'Same key and different request is forbidden.';
        $this->expectException(RecordWriteRejected::class); $this->save($batch, $row, $changed, $key);
    }

    public function test_changes_to_source_agreement_or_contact_are_precise_blockers_never_echoed_as_values(): void
    {
        $this->confirmed();
        [$batch, $row, $view, $input] = $this->update(['event_address' => 'PRIVATE_CHANGED_ADDRESS', 'client_email' => 'different@example.test', 'package_total' => '110.01', 'total' => '130.00', 'balance' => '40.00']);
        $this->assertSame('blocked', $view['kind']);
        $this->assertContains('event_address', array_column($view['blocked'], 'field'));
        $this->assertContains('client_email', array_column($view['blocked'], 'field'));
        $this->assertContains('total', array_column($view['blocked'], 'field'));
        $this->assertStringNotContainsString('PRIVATE_CHANGED_ADDRESS', json_encode($view));
        $this->assertStringNotContainsString('different@example.test', json_encode($view));
        try { $this->save($batch, $row, $input); $this->fail('Commercial changes accepted'); } catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        $this->assertDatabaseCount('imported_project_revisions', 1);
    }

    public function test_changed_customer_or_resource_snapshot_invalidates_displayed_diff_without_replacing_anything(): void
    {
        $this->confirmed(); [$batch, $row, $view, $input] = $this->update();
        Customer::whereKey($view['current']['customer_id'])->increment('version');
        try { $this->save($batch, $row, $input); $this->fail('Stale contact accepted'); } catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        Event::sole()->services()->first()->forceFill(['requirements_snapshot' => [['kind' => 'staff', 'quantity' => 10]]])->save();
        $fresh = app(ReadHoneyBookProjectDiff::class)->execute($this->owner, $batch->id, $row->id);
        $this->assertContains('operations_changed', array_column($fresh['blocked'], 'code'));
        $this->assertDatabaseCount('imported_project_revisions', 1);
    }

    public function test_existing_source_total_difference_requires_explicit_retention_and_cannot_be_widened(): void
    {
        $this->confirmed(['total' => '123.60', 'amount_paid' => '70.00', 'balance' => '53.60', 'payments' => '2026-10-01|70.00|Cash'], ['source_difference_accepted' => true, 'source_difference_note' => 'PRIVATE_INITIAL_ACCEPTANCE included source fee.']);
        [$batch, $row, $view, $input] = $this->update(['total' => '123.60', 'balance' => '33.60']);
        $this->assertTrue($view['source_difference']['can_retain']);
        $missing = $input; unset($missing['resync']['retain_source_difference_revision_id']);
        try { $this->save($batch, $row, $missing); $this->fail('Difference silently accepted'); } catch (RecordWriteRejected) {}
        $link = $this->save($batch, $row, $input);
        $r = ImportedProjectRevision::findOrFail($link->current_revision_id);
        $this->assertSame(1, $r->mapping['source_difference']['retained_from_revision_id']);
        $this->assertSame('PRIVATE_INITIAL_ACCEPTANCE included source fee.', $r->mapping['source_difference']['note']);
        $this->assertTrue(app(ReadImportCoverage::class)->execute($this->owner, $batch->id)['domain_complete']);
        [, , $blocked] = $this->update(['total' => '125.00', 'balance' => '35.00']);
        $this->assertFalse($blocked['source_difference']['can_retain']);
        $this->assertSame('blocked', $blocked['kind']);
    }

    public function test_known_past_project_accepts_source_money_but_unknown_past_and_cancelled_cannot_be_unskipped(): void
    {
        $link = $this->confirmed();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-12-22T12:00:00Z'));
        [$batch, $row, $view, $input] = $this->update(['amount_paid' => '120.00', 'balance' => '0.00', 'balance_due_date' => '', 'payments' => '2026-12-21|120.00|Cash']);
        $this->assertSame('past', $row->skip_reason);
        $this->assertNotSame('skipped', $row->status);
        $this->assertSame('money_only', $view['kind']);
        $this->save($batch, $row, $input);
        $this->assertNull(ImportedProjectRevision::findOrFail($link->fresh()->current_revision_id)->normalized['balance_due_date']);
        $unknown = $this->stageFuture(['honeybook_project_id' => 'UNKNOWN-PAST']);
        $unknownRow = ImportRow::where('import_batch_id', $unknown->id)->sole();
        $this->assertSame('skipped', $unknownRow->status);
        try { $this->save($unknown, $unknownRow, $this->commitInput($unknown, $unknownRow)); $this->fail('Unknown past created'); } catch (RecordWriteRejected) {}
        $cancelled = $this->stageFuture(['status' => 'cancelled']);
        $this->assertSame('skipped', ImportRow::where('import_batch_id', $cancelled->id)->sole()->status);
        $this->assertDatabaseCount('events', 1);
    }

    public function test_source_update_without_new_protocol_and_after_crm_takeover_is_refused(): void
    {
        $this->confirmed(); [$batch, $row, $view, $input] = $this->update();
        try { $this->save($batch, $row, $this->commitInput($batch, $row, ['expected_import_version' => 1, 'expected_event_version' => 2])); $this->fail('Legacy protocol overwrote confirmed event'); } catch (RecordWriteRejected) {}
        Event::sole()->forceFill(['responsible_system' => 'crm'])->save();
        $fresh = app(ReadHoneyBookProjectDiff::class)->execute($this->owner, $batch->id, $row->id);
        $this->assertContains('responsibility_changed', array_column($fresh['blocked'], 'code'));
        $this->expectException(RecordWriteRejected::class); $this->save($batch, $row, $input);
    }

    public function test_source_refund_below_original_deposit_and_overpayment_remain_unverified_facts_without_rebooking(): void
    {
        $link = $this->confirmed(); $eventVersion = Event::sole()->version;
        [$batch, $row, , $input] = $this->update(['amount_paid' => '50.00', 'balance' => '70.00',
            'payments' => '2026-10-01|70.00|Zelle;2026-10-16|-20.00|source refund']);
        $this->save($batch, $row, $input);
        $this->assertSame(5000, ImportedAgreement::summary($link->event_id)['net_received_cents']);
        $this->assertTrue(app(ReadImportCoverage::class)->execute($this->owner, $batch->id)['capacity_complete']);
        [$batch, $row, , $input] = $this->update(['amount_paid' => '130.00', 'balance' => '-10.00', 'balance_due_date' => '', 'payments' => '2026-10-16|130.00|Cash']);
        $saved = $this->save($batch, $row, $input);
        $summary = ImportedAgreement::summary($link->event_id);
        $this->assertSame(1000, $summary['credit_cents']); $this->assertSame(0, $summary['balance_cents']);
        $this->assertSame(3, $saved->version); $this->assertSame($eventVersion, Event::sole()->version);
        $this->assertDatabaseCount('booking_evidence', 1); $this->assertDatabaseCount('resource_reservations', 2); $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_changed_reservation_invalidates_capacity_and_a_previously_displayed_review(): void
    {
        $this->confirmed(); [$batch, $row, $view, $input] = $this->update();
        $replacement = Resource::create(['code' => 'DIFFERENT-HOST', 'name' => 'Fictional replacement', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'driver']]);
        DB::table('resource_reservations')->where('resource_id', BookingEvidence::sole()->staff_assignment_snapshot['assignments'][0]['resource_id'])->update(['resource_id' => $replacement->id]);
        $fresh = app(ReadHoneyBookProjectDiff::class)->execute($this->owner, $batch->id, $row->id);
        $this->assertNotSame($view['diff_sha256'], $fresh['diff_sha256']);
        $this->assertSame('blocked', $fresh['kind']);
        $this->expectException(RecordWriteRejected::class); $this->save($batch, $row, $input);
    }

    public function test_review_endpoint_and_commit_require_import_flag_and_secure_staging_transport(): void
    {
        $this->confirmed(); [$batch, $row, , $input] = $this->update();
        $url = '/admin/api/imports/honeybook/'.$batch->id.'/rows/'.$row->id;
        config(['waoo.sandbox_imports' => false]);
        $this->getJson($url.'/diff')->assertForbidden();
        $this->withHeader('Idempotency-Key', (string)Str::uuid())->postJson($url.'/commit', $input)->assertForbidden();
        config(['waoo.sandbox_imports' => true]); $this->app['env'] = 'staging';
        $this->getJson($url.'/diff')->assertForbidden();
        $this->withHeader('Idempotency-Key', (string)Str::uuid())->postJson($url.'/commit', $input)->assertForbidden();
        $this->assertDatabaseCount('imported_project_revisions', 1);
    }

    public function test_audit_failure_rolls_back_head_source_ledger_batch_and_receipt(): void
    {
        $link = $this->confirmed(); [$batch, $row, $view, $input] = $this->update();
        $receipts = DB::table('mutation_receipts')->count(); $fail = true;
        DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$fail) {
            if ($fail && str_contains($sql, 'audit_entries') && in_array('import.money_resynced', $bindings, true)) throw new \RuntimeException('Synthetic resync audit failure');
        });
        try { $this->save($batch, $row, $input); $this->fail('Expected rollback'); } catch (\RuntimeException $e) { $this->assertSame('Synthetic resync audit failure', $e->getMessage()); } finally { $fail = false; }
        $this->assertSame($link->current_revision_id, $link->fresh()->current_revision_id);
        $this->assertSame($batch->version, $batch->fresh()->version);
        $this->assertSame($receipts, DB::table('mutation_receipts')->count());
        $this->assertDatabaseCount('imported_project_revisions', 1); $this->assertDatabaseCount('imported_ledger_lines', 2);
    }

    public function test_private_diff_http_contract_and_commit_result_use_owner_auth_and_no_store(): void
    {
        $this->confirmed(); [$batch, $row, $view, $input] = $this->update();
        $url = '/admin/api/imports/honeybook/'.$batch->id.'/rows/'.$row->id;
        $response = $this->getJson($url.'/diff')->assertOk()->assertJsonPath('kind', 'money_only')->assertDontSee('PRIVATE_SOURCE_NOTE_FIXTURE');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->withHeader('Idempotency-Key', (string)Str::uuid())->postJson($url.'/commit', $input)->assertOk()->assertJsonPath('operation', 'money_resync');
        $this->getJson('/admin/api/imports/honeybook/999999/rows/'.$row->id.'/diff')->assertNotFound();
        $this->owner->forceFill(['active' => false])->save(); $this->getJson($url.'/diff')->assertForbidden();
    }

    public function test_unreviewed_payload_browser_amounts_and_tampered_source_artifact_cannot_update_money(): void
    {
        $this->confirmed(); [$batch, $row, $view, $input] = $this->update();
        foreach ([['total_cents' => 1], ['services' => []], ['resync' => [...$input['resync'], 'owner_money_reviewed' => false]], ['resync' => [...$input['resync'], 'balance_cents' => 0]]] as $change) {
            try { $this->save($batch, $row, [...$input, ...$change]); $this->fail('Untrusted input accepted'); } catch (ValidationException) {}
        }
        $file = ImportFile::where('import_batch_id', $batch->id)->sole();
        Storage::disk('local')->put($file->private_path, 'Corrupted synthetic source bytes');
        try { $this->save($batch, $row, $input); $this->fail('Tampered artifact accepted'); } catch (\RuntimeException $e) { $this->assertSame('Private import artifact unavailable.', $e->getMessage()); }
        $this->assertDatabaseCount('imported_project_revisions', 1);
    }

    public function test_two_owners_race_one_review_without_duplicate_source_revisions(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires real MariaDB/pcntl');
        $this->confirmed(); [$batch, $row, $view, $input] = $this->update();
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $worker = fn ($actor) => function () use ($actor, $batch, $row, $input) {
            try { app(CommitHoneyBookEvent::class)->execute($actor, $batch->id, $row->id, $input, (string)Str::uuid()); return 'updated'; }
            catch (RecordWriteRejected $e) { return $e->status; }
        };
        $results = ConcurrentWorkers::run([$worker($this->owner), $worker($other)]);
        $this->assertContains('updated', $results); $this->assertContains(409, $results, json_encode($results));
        $this->assertDatabaseCount('imported_project_revisions', 2); $this->assertDatabaseCount('imported_ledger_lines', 5);
        $this->assertSame(9000, ImportedAgreement::summary(Event::sole()->id)['net_received_cents']);
    }

    public function test_retry_with_an_older_repeatable_read_snapshot_restarts_outer_transaction_without_duplicates(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires real MariaDB/pcntl');
        $this->confirmed(); [$batch, $row, $view, $input] = $this->update(); $key = (string)Str::uuid();
        $dir = sys_get_temp_dir().'/waoo-resync-snapshot-'.bin2hex(random_bytes(6)); mkdir($dir, 0700);
        $wait = function (string $path): void {
            $deadline = microtime(true) + 15;
            while (!file_exists($path) && microtime(true) < $deadline) usleep(1000);
            if (!file_exists($path)) throw new \RuntimeException('Synthetic snapshot barrier timed out');
        };
        try {
            $results = ConcurrentWorkers::run([
                function () use ($batch, $row, $input, $key, $dir, $wait) {
                    $wait($dir.'/snapshot');
                    $saved = $this->save($batch, $row, $input, $key);
                    touch($dir.'/committed');
                    return $saved->id;
                },
                function () use ($batch, $row, $input, $key, $dir, $wait) {
                    return DB::transaction(function () use ($batch, $row, $input, $key, $dir, $wait) {
                        DB::table('mutation_receipts')->count(); // Establish a real old RR read view before the other commit.
                        touch($dir.'/snapshot'); $wait($dir.'/committed');
                        return $this->save($batch, $row, $input, $key)->id;
                    }, 3); // MariaDB 11.8 snapshot isolation may require restarting the entire outer transaction.
                },
            ]);
            $id = ImportedProjectLink::sole()->id;
            $this->assertSame([$id, $id], $results);
            $this->assertDatabaseCount('imported_project_revisions', 2);
            $this->assertSame(1, DB::table('mutation_receipts')->where('request_key', $key)->count());
        } finally { foreach (glob($dir.'/*') as $file) unlink($file); rmdir($dir); }
    }
}
