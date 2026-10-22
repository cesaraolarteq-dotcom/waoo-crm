<?php
namespace Tests\Feature;

use App\Models\{HistoricalEvent, User};
use App\Services\History\{StageHistoricalImport, ReadHistoricalImport, ImportSafeHistoricalRows};
use App\Services\Reports\ReadBookingAnalytics;
use App\Services\Campaigns\ReadBirthdayCampaignDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\HistoricalSourceFixture;
use Tests\TestCase;

class OwnerAnalyticsHistoryTest extends TestCase
{
    use RefreshDatabase, HistoricalSourceFixture;

    public function test_real_archive_pipeline_projects_source_totals_and_drafts_without_messages(): void
    {
        config(['waoo.sandbox_history'=>true,'waoo.sandbox_analytics'=>true,'waoo.sandbox_anniversary'=>true]);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-21T16:00:00Z'));
        $owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $this->actingAs($owner);
        $row=$this->historyRow(['event_date'=>'2025-12-10','event_type'=>'birthday','project_status'=>'completed','lead_source'=>'Instagram','created_at'=>'2025-11-10']);
        $id=app(StageHistoricalImport::class)->execute($owner,$this->historyFile([$row],array_keys($row)),(string)Str::uuid())['batch_id'];
        $read=app(ReadHistoricalImport::class)->execute($owner,$id);
        app(ImportSafeHistoricalRows::class)->execute($owner,$id,['expected_batch_version'=>$read['batch']['version'],
            'expected_coverage_sha256'=>$read['coverage_sha256'],'expected_plan_sha256'=>$read['bulk']['plan_sha256'],'new_customer_locale'=>'es'],(string)Str::uuid());
        $r=app(ReadBookingAnalytics::class)->execute($owner,['year'=>2025]);
        $this->assertSame(1,$r['booking_count']); $this->assertSame(['honeybook'=>1],$r['source_counts']);
        $this->assertSame(['instagram'=>1],$r['lead_sources']); $this->assertSame(30.0,$r['lead_time']['average_days']);
        $this->assertSame(12000,$r['ticket']['average_cents']); $this->assertStringNotContainsString('María',json_encode($r));
        $draft=app(ReadBirthdayCampaignDraft::class)->execute($owner,['as_of'=>'2026-10-21']);
        $this->assertSame(1,$draft['candidate_count']); $this->assertFalse($draft['delivery_enabled']);
        config(['waoo.sandbox_finance'=>true]);
        \Livewire\Livewire::test(\App\Filament\Pages\Reports::class)->set('analyticsYear',2025)
            ->assertSee('Cumpleaños del próximo año · solo borrador')->assertSee('2026-11-10')
            ->assertSee('faltan permisos promocionales verificables');
        $this->assertDatabaseCount('outbound_messages',0); $this->assertDatabaseCount('events',0); $this->assertDatabaseCount('ledger_entries',0);
        config(['waoo.sandbox_anniversary'=>false]);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(ReadBirthdayCampaignDraft::class)->execute($owner,['as_of'=>'2026-10-21']);
    }
}
