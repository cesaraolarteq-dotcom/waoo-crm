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

    public function test_relabel_preserves_existing_card_fee_evidence(): void
    {
        $this->scenario();
        $finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => $this->event->version], (string) Str::uuid());
        $intent = new \App\Models\PaymentIntent(['id' => (string) Str::uuid(), 'event_id' => $this->event->id, 'financial_revision_id' => $finance->id,
            'purpose' => 'balance', 'status' => 'creating', 'currency' => 'USD', 'environment' => 'test', 'principal_cents' => 10000,
            'customer_fee_cents' => 300, 'payable_cents' => 10300, 'card_fee_policy' => \App\Services\Finance\CardFees::POLICY, 'expires_at' => now()->addHour()]);
        $intent->card_fee_terms_sha256 = \App\Services\Finance\CardFees::fingerprint($intent);
        $intent->save();
        try {
            $this->app['env'] = 'production';
            config(['waoo.live' => true]);
            (require database_path('migrations/2026_11_20_120000_relabel_live_money_rows.php'))->up();
            $migrated = $intent->fresh();
            $this->assertSame('live', $migrated->environment);
            $this->assertSame($intent->card_fee_terms_sha256, $migrated->card_fee_terms_sha256);
            $this->assertTrue(\App\Services\Finance\CardFees::valid($migrated));
            $migrated->card_fee_terms_sha256 = str_repeat('0', 64);
            $this->assertFalse(\App\Services\Finance\CardFees::valid($migrated));
        } finally {
            $this->app['env'] = 'testing';
            $intent->update(['card_fee_policy' => null, 'card_fee_terms_sha256' => null]);
        }
    }
}
