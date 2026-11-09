<?php
namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Services\Finance\{CreateFinancialRevision, FinanceSummary, RecordZellePayment};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\Support\QuoteScenario;
use Tests\TestCase;

// Owner 2026-11-05 review: on the real CRM every money row says `live`, and every reader counts it.
class LiveLedgerTest extends TestCase
{
    use DatabaseMigrations, QuoteScenario;

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        parent::tearDown();
    }

    public function test_real_crm_writes_and_reads_live_money(): void
    {
        $this->scenario();
        $this->app['env'] = 'production';
        config(['waoo.live' => true]);
        $finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => $this->event->version], (string) Str::uuid());
        app(RecordZellePayment::class)->execute($this->owner, $this->event->id, ['financial_revision_id' => $finance->id, 'amount_cents' => 5000, 'currency' => 'USD',
            'reference' => 'LIVE-ZELLE-1', 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true], (string) Str::uuid());

        $this->assertSame(['live'], LedgerEntry::where('event_id', $this->event->id)->pluck('environment')->unique()->values()->all());
        $summary = app(FinanceSummary::class)->forEvent($this->event->fresh());
        $this->assertSame('live', $summary['environment']);
        $this->assertSame($finance->total_cents - 5000, $summary['balance_cents']);
    }

    public function test_test_environments_keep_the_test_label(): void
    {
        $this->assertSame('test', \App\Support\WaooRuntime::ledgerEnvironment());
    }
}
