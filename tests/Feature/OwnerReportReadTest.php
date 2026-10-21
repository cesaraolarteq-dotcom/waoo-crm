<?php
namespace Tests\Feature;

use App\Models\{OwnerReportSnapshot, User};
use App\Services\Reports\{PrepareOwnerReport, ReadOwnerReport, RenderOwnerReportEmail};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail, Queue};
use Tests\TestCase;

class OwnerReportReadTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00', 'America/Chicago'));
        config(['waoo.sandbox_finance' => true]);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner); Mail::fake(); Queue::fake();
    }
    public function test_panel_reads_completed_period_without_creating_a_snapshot_or_sending(): void
    {
        $this->getJson('/admin/api/reports/owner?kind=weekly&as_of=2026-11-09')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('due_on', '2026-11-09')
            ->assertJsonPath('operations.period.starts_on', '2026-11-02')
            ->assertJsonPath('upcoming_weekend.window.starts_on', '2026-11-14')
            ->assertJsonPath('delivery.enabled', false);
        $this->getJson('/admin/api/reports/owner?kind=monthly&as_of=2026-11-09')->assertOk()
            ->assertJsonPath('operations.period.starts_on', '2026-10-01')->assertJsonPath('upcoming_weekend', null);
        $this->assertDatabaseCount('owner_report_snapshots', 0);
        Mail::assertNothingSent(); Queue::assertNothingPushed();
    }
    public function test_preview_uses_frozen_bytes_and_rejects_tampering(): void
    {
        $snapshot = app(PrepareOwnerReport::class)->execute($this->owner, ['kind' => 'weekly', 'as_of' => '2026-11-09']);
        $before = app(RenderOwnerReportEmail::class)->execute($this->owner, $snapshot->id);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00', 'America/Chicago'));
        $this->assertSame($before, app(RenderOwnerReportEmail::class)->execute($this->owner, $snapshot->id));
        $this->assertLessThanOrEqual(60, mb_strlen($before['subject']));
        $this->assertSame('Semana del 2 de nov. al 8 de nov. 2026', $before['subject']);
        $this->assertStringNotContainsString('fin exclusivo', $before['text']);
        $this->assertFalse($before['sending_enabled']);
        $this->assertStringContainsString('sin enviar', $before['text']);
        $this->getJson('/admin/api/reports/snapshots/'.$snapshot->id.'/email-preview')->assertOk()->assertJsonPath('approved', false);
        DB::table('owner_report_snapshots')->where('id', $snapshot->id)->update(['payload_json' => '{}']);
        $this->getJson('/admin/api/reports/snapshots/'.$snapshot->id.'/email-preview')->assertStatus(409);
        Mail::assertNothingSent(); Queue::assertNothingPushed();
    }
    public function test_staging_requires_secure_transport_and_sandbox_flag(): void
    {
        config(['waoo.sandbox_finance' => false]);
        $this->getJson('/admin/api/reports/owner?kind=weekly&as_of=2026-11-09')->assertForbidden();
        config(['waoo.sandbox_finance' => true, 'session.secure' => false]);
        $this->app->instance('env', 'staging');
        $this->getJson('/admin/api/reports/owner?kind=weekly&as_of=2026-11-09')->assertForbidden();
        config(['session.secure' => true]);
        $this->getJson('https://localhost/admin/api/reports/owner?kind=weekly&as_of=2026-11-09')->assertOk();
    }
    public function test_invalid_dates_and_inactive_actors_are_rejected(): void
    {
        $this->getJson('/admin/api/reports/owner?kind=weekly&as_of=2026-11-10')->assertUnprocessable();
        $this->getJson('/admin/api/reports/owner?kind=weekly&as_of=2026-02-30')->assertUnprocessable();
        $this->owner->forceFill(['active' => false])->save();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(ReadOwnerReport::class)->execute($this->owner, ['kind' => 'weekly', 'as_of' => '2026-11-09']);
    }
}
