<?php

namespace Tests\Feature;

use App\Models\{OwnerReportSnapshot, ReportPreviewDelivery, User};
use App\Services\Reports\Outbox\{DatabasePreviewTransport, EnqueueReportPreview, PreviewTransport, ProcessReportPreview};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Mail, Notification, Queue};
use Illuminate\Support\Str;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;
use App\Services\Reports\Outbox\CancelReportPreview;

class CancelReportPreviewTest extends TestCase
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
            public function record(int $deliveryId, string $key, string $payloadJson, string $leaseToken): string { return ($this->callback)($deliveryId, $key, $payloadJson, $leaseToken); }
        });
    }
    private function cancel(int $id): ReportPreviewDelivery
    {
        return app(CancelReportPreview::class)->execute($this->owner, $id, ['note' => 'Fixture owner cancels this local preview.']);
    }
    private function url(): string { return '/admin/api/reports/snapshots/'.$this->snapshot->id.'/preview-delivery'; }
    public function test_cancel_is_audited_idempotent_and_does_not_rearm_or_process(): void
    {
        $this->getJson($this->url())->assertNotFound(); $this->assertDatabaseCount('report_preview_deliveries', 0);
        $delivery = $this->enqueue();
        $this->postJson($this->url().'/cancel', ['note' => 'Fixture owner cancels this local preview.'])->assertOk()
            ->assertJsonPath('status', 'cancelled')->assertJsonPath('cancelled_by', $this->owner->id)->assertJsonMissingPath('lease_token');
        $first = $delivery->fresh();
        $this->postJson($this->url().'/cancel', ['note' => 'Different valid note cannot replace history.'])->assertOk()->assertJsonPath('cancellation_note', $first->cancellation_note);
        $this->getJson($this->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('status', 'cancelled')->assertJsonMissingPath('lease_token');
        $this->assertSame('cancelled', $this->enqueue()->status); $this->assertSame('cancelled', $this->process($delivery->id)->status);
        $this->artisan('reports:process-previews')->assertSuccessful();
        $this->assertSame(0, $delivery->fresh()->attempts); $this->assertDatabaseCount('report_preview_receipts', 0);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'report.preview_cancelled')->count());
        Mail::assertNothingSent(); Queue::assertNothingPushed();
    }
    public function test_cancellation_after_claim_blocks_receipt_and_old_worker_ack(): void
    {
        $delivery = $this->enqueue();
        $this->adapter(function ($id, $key, $json, $token) {
            $this->cancel($id);
            return (new DatabasePreviewTransport())->record($id, $key, $json, $token);
        });
        $result = $this->process($delivery->id);
        $this->assertSame('cancelled', $result->status); $this->assertSame(1, $result->attempts);
        $this->assertNull($result->lease_token); $this->assertNull($result->last_error_code);
        $this->assertDatabaseCount('report_preview_receipts', 0);
    }
    public function test_committed_local_effect_cannot_be_cancelled_before_ack_or_after_completion(): void
    {
        $delivery = $this->enqueue(); $token = (string) Str::uuid();
        $delivery->forceFill(['status' => 'processing', 'attempts' => 1, 'lease_token' => $token, 'lease_until' => now()->addSeconds(90)])->save();
        (new DatabasePreviewTransport())->record($delivery->id, hash('sha256', 'report-preview:test:'.$delivery->id.':v1'), $this->snapshot->payload_json, $token);
        $this->postJson($this->url().'/cancel', ['note' => 'Fixture cancellation arrived after local receipt.'])->assertConflict();
        $this->assertSame('processing', $delivery->fresh()->status);
        $this->travel(91)->seconds(); $this->assertSame('completed', $this->process($delivery->id)->status);
        $this->postJson($this->url().'/cancel', ['note' => 'Fixture completed preview cannot be recalled.'])->assertConflict();
        $this->assertDatabaseCount('report_preview_receipts', 1);
        $this->assertSame(0, DB::table('audit_entries')->where('action', 'report.preview_cancelled')->count());
    }
    public function test_actual_cancel_vs_worker_race_has_one_linearized_outcome(): void
    {
        $id = $this->enqueue()->id;
        $results = ConcurrentWorkers::run([
            fn () => $this->process($id)->status,
            function () use ($id) {
                try { return $this->cancel($id)->status; }
                catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { return $e->getStatusCode() === 409 ? 'conflict' : 'unexpected'; }
            },
        ]);
        $final = ReportPreviewDelivery::findOrFail($id)->status;
        $this->assertContains($final, ['completed', 'cancelled']);
        $this->assertContains($results[0], ['completed', 'cancelled']);
        $this->assertContains($results[1], ['conflict', 'cancelled']);
        $this->assertDatabaseCount('report_preview_receipts', $final === 'completed' ? 1 : 0);
        $this->assertSame(1, DB::table('audit_entries')->whereIn('action', ['report.preview_completed', 'report.preview_cancelled'])->count());
    }
    public function test_failed_cancel_audit_restores_original_claim_and_can_retry(): void
    {
        $delivery = $this->enqueue(); $token = (string) Str::uuid();
        $delivery->forceFill(['status' => 'processing', 'attempts' => 1, 'lease_token' => $token, 'lease_until' => now()->addSeconds(90)])->save();
        $fail = true;
        DB::connection()->beforeExecuting(function ($query, $bindings) use (&$fail) {
            if ($fail && str_contains($query, 'audit_entries') && in_array('report.preview_cancelled', $bindings, true)) throw new \RuntimeException('Fixture cancellation audit');
        });
        try { $this->cancel($delivery->id); $this->fail('Cancellation audit failure ignored.'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture cancellation audit', $e->getMessage()); }
        finally { $fail = false; }
        $this->assertSame('processing', $delivery->fresh()->status); $this->assertSame($token, $delivery->fresh()->lease_token);
        $this->assertSame('cancelled', $this->cancel($delivery->id)->status);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'report.preview_cancelled')->count());
    }
    public function test_stale_or_wrong_transport_claim_cannot_create_receipt(): void
    {
        $delivery = $this->enqueue(); $token = (string) Str::uuid();
        $delivery->forceFill(['status' => 'processing', 'attempts' => 1, 'lease_token' => $token, 'lease_until' => now()->addSeconds(90)])->save();
        $key = hash('sha256', 'report-preview:test:'.$delivery->id.':v1');
        try { (new DatabasePreviewTransport())->record($delivery->id, $key, $this->snapshot->payload_json, 'wrong-token'); $this->fail('Wrong claim accepted.'); }
        catch (\LogicException) { $this->assertDatabaseCount('report_preview_receipts', 0); }
        $this->travel(91)->seconds();
        try { (new DatabasePreviewTransport())->record($delivery->id, $key, $this->snapshot->payload_json, $token); $this->fail('Expired claim accepted.'); }
        catch (\LogicException) { $this->assertDatabaseCount('report_preview_receipts', 0); }
        $this->assertSame('completed', $this->process($delivery->id)->status);
    }
    public function test_failed_terminal_task_is_not_rewritten_as_cancelled(): void
    {
        $delivery = $this->enqueue(); $delivery->forceFill(['status' => 'failed', 'last_error_code' => 'attempt_limit'])->save();
        $this->postJson($this->url().'/cancel', ['note' => 'Fixture failed task is already terminal.'])->assertConflict();
        $this->assertSame('failed', $delivery->fresh()->status); $this->assertNull($delivery->fresh()->cancelled_at);
    }
    public function test_note_and_owner_2fa_preview_gates_apply_to_cancel_and_status(): void
    {
        $id = $this->enqueue()->id;
        $this->postJson($this->url().'/cancel', ['note' => 'short'])->assertUnprocessable();
        config(['waoo.sandbox_report_outbox' => false]); $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url().'/cancel', ['note' => 'Fixture disabled gate blocks cancellation.'])->assertForbidden();
        config(['waoo.sandbox_report_outbox' => true]); $this->owner->forceFill(['active' => false])->save();
        try { $this->cancel($id); $this->fail('Inactive owner cancelled.'); }
        catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertTrue(true); }
        $this->owner->forceFill(['active' => true, 'role' => 'staff'])->save(); $this->getJson($this->url())->assertForbidden();
        $this->owner->forceFill(['role' => 'owner_admin', 'two_factor_confirmed_at' => null])->save(); $this->getJson($this->url())->assertForbidden();
        auth()->logout(); $this->app['auth']->forgetGuards(); $this->getJson($this->url())->assertUnauthorized();
        $this->postJson($this->url().'/cancel', ['note' => 'Fixture anonymous cancellation denied.'])->assertUnauthorized();
        $this->assertSame('pending', ReportPreviewDelivery::findOrFail($id)->status);
    }
}
