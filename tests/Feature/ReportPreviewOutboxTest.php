<?php

namespace Tests\Feature;

use App\Models\{OwnerReportSnapshot, ReportPreviewDelivery, User};
use App\Services\Reports\Outbox\{DatabasePreviewTransport, EnqueueReportPreview, PreviewTransport, ProcessReportPreview};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Mail, Notification, Queue};
use Illuminate\Support\Str;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class ReportPreviewOutboxTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private OwnerReportSnapshot $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_report_outbox' => true]);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 10:00:00', 'America/Chicago'));
        Mail::fake(); Queue::fake(); Notification::fake();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        $json = '{"schema_version":1,"fixture":"Reporte de prueba · sin enviar"}';
        $this->snapshot = OwnerReportSnapshot::create(['environment' => 'test', 'kind' => 'weekly', 'period_starts_on' => '2026-11-02',
            'period_ends_before' => '2026-11-09', 'recipient' => 'sales@waooparty.com', 'payload_json' => $json,
            'payload_sha256' => hash('sha256', $json), 'prepared_by' => $this->owner->id]);
    }
    private function enqueue(): ReportPreviewDelivery { return app(EnqueueReportPreview::class)->execute($this->owner, $this->snapshot->id); }
    private function process(int $id): ReportPreviewDelivery { return app(ProcessReportPreview::class)->execute($id); }
    private function adapter(\Closure $callback): void
    {
        app()->bind(PreviewTransport::class, fn () => new class($callback) implements PreviewTransport {
            public function __construct(private \Closure $callback) {}
            public function record(int $deliveryId, string $key, string $payloadJson): string { return ($this->callback)($deliveryId, $key, $payloadJson); }
        });
    }
    public function test_http_enqueue_retries_share_one_company_delivery_and_never_expose_lease_or_send(): void
    {
        $url = '/admin/api/reports/snapshots/'.$this->snapshot->id.'/preview-delivery';
        $first = $this->postJson($url)->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('status', 'pending')
            ->assertJsonMissingPath('lease_token')->json('id');
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($other)->postJson($url)->assertCreated()->assertJsonPath('id', $first);
        $this->assertDatabaseCount('report_preview_deliveries', 1);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'report.preview_queued')->count());
        $this->assertSame('completed', $this->process($first)->status);
        $this->assertSame('completed', $this->process($first)->status);
        $this->assertDatabaseCount('report_preview_receipts', 1);
        $this->assertSame(1, ReportPreviewDelivery::sole()->attempts);
        Mail::assertNothingSent(); Queue::assertNothingPushed(); Notification::assertNothingSent();
    }
    public function test_transport_failure_is_sanitized_and_backoff_is_respected(): void
    {
        $delivery = $this->enqueue();
        $this->adapter(fn () => throw new \RuntimeException('PRIVATE SECRET FIXTURE NEVER LOG'));
        $failed = $this->process($delivery->id);
        $this->assertSame('pending', $failed->status); $this->assertSame('transport_error', $failed->last_error_code);
        $this->assertSame(60, (int) now()->diffInSeconds($failed->available_at));
        $this->process($delivery->id); $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertStringNotContainsString('PRIVATE', json_encode($failed));
        $this->travel(61)->seconds(); app()->bind(PreviewTransport::class, DatabasePreviewTransport::class);
        $this->assertSame('completed', $this->process($delivery->id)->status);
        $this->assertSame(2, $delivery->fresh()->attempts);
    }
    public function test_completed_transport_survives_lost_ack_and_expired_lease_retries_same_receipt(): void
    {
        $delivery = $this->enqueue(); $fail = true;
        DB::connection()->beforeExecuting(function ($query, $bindings) use (&$fail) {
            if ($fail && str_contains($query, 'audit_entries') && in_array('report.preview_completed', $bindings, true)) throw new \RuntimeException('Fixture lost ack');
        });
        try { $this->process($delivery->id); $this->fail('Ack failure ignored.'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture lost ack', $e->getMessage()); }
        finally { $fail = false; }
        $this->assertSame('processing', $delivery->fresh()->status); $this->assertDatabaseCount('report_preview_receipts', 1);
        $reference = DB::table('report_preview_receipts')->value('reference');
        $this->process($delivery->id); $this->assertSame(1, $delivery->fresh()->attempts);
        $this->travel(91)->seconds();
        $this->assertSame('completed', $this->process($delivery->id)->status);
        $this->assertDatabaseCount('report_preview_receipts', 1);
        $this->assertSame($reference, DB::table('report_preview_receipts')->value('reference'));
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'report.preview_completed')->count());
    }
    public function test_late_worker_failure_cannot_overwrite_success_by_newer_lease(): void
    {
        $delivery = $this->enqueue();
        $this->adapter(function ($id, $key, $json) {
            (new DatabasePreviewTransport())->record($id, $key, $json);
            $this->travel(91)->seconds();
            (new ProcessReportPreview(new DatabasePreviewTransport()))->execute($id);
            throw new \RuntimeException('Fixture stale worker failure');
        });
        $result = $this->process($delivery->id);
        $this->assertSame('completed', $result->status); $this->assertSame(2, $result->attempts);
        $this->assertNull($result->last_error_code); $this->assertNull($result->lease_token);
        $this->assertDatabaseCount('report_preview_receipts', 1);
    }
    public function test_five_failures_stop_retries_and_record_one_exhaustion(): void
    {
        $delivery = $this->enqueue(); $this->adapter(fn () => throw new \RuntimeException('Fixture failure'));
        foreach ([60, 120, 240, 480] as $delay) {
            $result = $this->process($delivery->id); $this->assertSame('pending', $result->status);
            $this->assertSame($delay, (int) now()->diffInSeconds($result->available_at));
            $this->travel($delay + 1)->seconds();
        }
        $this->assertSame('failed', $this->process($delivery->id)->status);
        $this->travel(1)->days(); $this->process($delivery->id);
        $this->assertSame(5, $delivery->fresh()->attempts); $this->assertDatabaseCount('report_preview_receipts', 0);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'report.preview_exhausted')->count());
    }
    public function test_payload_corruption_fails_closed_without_transport_or_sensitive_audit(): void
    {
        $delivery = $this->enqueue();
        DB::table('owner_report_snapshots')->where('id', $this->snapshot->id)->update(['payload_json' => 'PRIVATE CORRUPTED FIXTURE']);
        $result = $this->process($delivery->id);
        $this->assertSame('failed', $result->status); $this->assertSame('payload_invalid', $result->last_error_code);
        $this->assertDatabaseCount('report_preview_receipts', 0);
        $this->assertStringNotContainsString('PRIVATE', json_encode(DB::table('audit_entries')->get()));
        $this->postJson('/admin/api/reports/snapshots/'.$this->snapshot->id.'/preview-delivery')->assertConflict();
    }
    public function test_enqueue_audit_failure_rolls_back_delivery(): void
    {
        $fail = true;
        DB::connection()->beforeExecuting(function ($query, $bindings) use (&$fail) {
            if ($fail && str_contains($query, 'audit_entries') && in_array('report.preview_queued', $bindings, true)) throw new \RuntimeException('Fixture enqueue audit');
        });
        try { $this->enqueue(); $this->fail('Audit failure ignored.'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture enqueue audit', $e->getMessage()); }
        finally { $fail = false; }
        $this->assertDatabaseCount('report_preview_deliveries', 0); $this->enqueue();
        $this->assertDatabaseCount('report_preview_deliveries', 1);
    }
    public function test_two_actual_workers_claim_once_and_record_one_preview(): void
    {
        $id = $this->enqueue()->id;
        $results = ConcurrentWorkers::run([fn () => $this->process($id)->status, fn () => $this->process($id)->status]);
        foreach ($results as $status) $this->assertContains($status, ['processing', 'completed']);
        $this->assertSame('completed', ReportPreviewDelivery::findOrFail($id)->status);
        $this->assertSame(1, ReportPreviewDelivery::findOrFail($id)->attempts); $this->assertDatabaseCount('report_preview_receipts', 1);
    }
    public function test_concurrent_owner_enqueue_has_one_outbox_and_audit(): void
    {
        $actor = $this->owner; $id = $this->snapshot->id;
        $worker = fn () => app(EnqueueReportPreview::class)->execute($actor, $id)->id;
        $results = ConcurrentWorkers::run([$worker, $worker]);
        $this->assertSame($results[0], $results[1]); $this->assertDatabaseCount('report_preview_deliveries', 1);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'report.preview_queued')->count());
    }
    public function test_crashed_fifth_claim_becomes_terminal_without_sixth_transport(): void
    {
        $delivery = $this->enqueue();
        $delivery->forceFill(['status' => 'processing', 'attempts' => 5, 'lease_until' => now()->subSecond(), 'lease_token' => (string) Str::uuid()])->save();
        $result = $this->process($delivery->id);
        $this->assertSame('failed', $result->status); $this->assertSame('attempt_limit', $result->last_error_code);
        $this->assertDatabaseCount('report_preview_receipts', 0);
    }
    public function test_manual_command_limit_and_due_filter_do_not_process_future_work(): void
    {
        $delivery = $this->enqueue(); $delivery->forceFill(['available_at' => now()->addHour()])->save();
        $this->artisan('reports:process-previews', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(0, $delivery->fresh()->attempts);
        $this->artisan('reports:process-previews', ['--limit' => 101])->assertExitCode(2);
        $this->travel(61)->minutes();
        $this->artisan('reports:process-previews', ['--limit' => 1])->assertSuccessful();
        $this->assertSame('completed', $delivery->fresh()->status);
        Mail::assertNothingSent(); Queue::assertNothingPushed();
    }
    public function test_gates_protect_http_direct_worker_and_production_even_with_flags(): void
    {
        $url = '/admin/api/reports/snapshots/'.$this->snapshot->id.'/preview-delivery';
        $id = $this->enqueue()->id;
        config(['waoo.sandbox_report_outbox' => false]); $this->postJson($url)->assertForbidden();
        try { $this->process($id); $this->fail('Disabled worker ran.'); } catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertTrue(true); }
        config(['waoo.sandbox_report_outbox' => true]); $this->owner->forceFill(['role' => 'staff'])->save(); $this->postJson($url)->assertForbidden();
        try { $this->enqueue(); $this->fail('Staff enqueued.'); } catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertTrue(true); }
        $this->owner->forceFill(['role' => 'owner_admin', 'two_factor_confirmed_at' => null])->save(); $this->postJson($url)->assertForbidden();
        auth()->logout(); $this->app['auth']->forgetGuards(); $this->postJson($url)->assertUnauthorized();
        $this->app->instance('env', 'production');
        try { $this->process($id); $this->fail('Production worker ran.'); } catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertTrue(true); } finally { $this->app->instance('env', 'testing'); }
        $this->assertDatabaseCount('report_preview_receipts', 0);
    }
}
