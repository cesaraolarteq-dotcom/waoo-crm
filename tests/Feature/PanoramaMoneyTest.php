<?php
namespace Tests\Feature;

use App\Models\Resource;
use App\Services\Costs\RecordCostSnapshot;
use App\Services\Finance\{ConfirmPaidCapacity, RecordZellePayment};
use App\Services\Reports\ReadPanorama;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\Support\QuoteScenario;
use Tests\TestCase;

final class PanoramaMoneyTest extends TestCase
{
    use DatabaseMigrations, QuoteScenario;

    public function test_profit_uses_discounted_package_sale_and_keeps_known_native_due_date(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-29T15:00:00Z'));
        $this->scenario(); $decision = $this->acceptOffer();
        $quote = \App\Models\Quote::findOrFail($decision->quote_id);
        $finance = app(\App\Services\Quotes\ConvertAcceptedQuote::class)->execute($this->owner, $quote->id,
            ['expected_version' => $quote->version, 'expected_revision' => $quote->current_revision,
                'expected_event_version' => $this->event->fresh()->version, 'decision_id' => $decision->id], (string)Str::uuid());
        $this->assertSame(2501, $finance->discount_cents);
        app(RecordZellePayment::class)->execute($this->owner, $this->event->id, ['financial_revision_id' => $finance->id,
            'amount_cents' => $finance->deposit_target_cents, 'currency' => 'USD', 'received_at' => now()->subMinute()->toIso8601String(),
            'reference' => 'PANORAMA-FICTIONAL-RECEIPT', 'verified_received' => true], (string)Str::uuid());
        $ids = [Resource::create(['code' => 'PAN-HOST', 'name' => 'Sofía Méndez', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer', 'driver']])->id,
            Resource::create(['code' => 'PAN-SPEAKER', 'name' => 'Speaker', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []])->id];
        app(ConfirmPaidCapacity::class)->execute($this->owner, $this->event->id, ['expected_version' => $this->event->fresh()->version,
            'financial_revision_id' => $finance->id, 'resource_ids' => $ids,
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Synthetic origin and return reviewed.', 'transfers' => []]]);
        app(RecordCostSnapshot::class)->execute($this->owner, $this->event->id, ['kind' => 'actual',
            'expected_event_version' => $this->event->fresh()->version, 'financial_revision_id' => $finance->id,
            'expected_revision' => 0, 'coverage_confirmed' => true, 'note' => 'Synthetic complete cost coverage.', 'lines' => [
                ['label' => 'Staff', 'category' => 'staff_labor', 'amount_cents' => 2500],
                ['label' => 'Paid staff transport', 'category' => 'staff_transport', 'amount_cents' => 1000],
                ['label' => 'Card costs excluded', 'category' => 'card_processing', 'amount_cents' => 500],
            ]], (string)Str::uuid());
        $view = app(ReadPanorama::class)->execute($this->owner, '2026-11-01', '2026-11-30');
        // Package10001 - approved discount2501 - staff2500 - paid transport1000 - materials3000 =1000.
        $this->assertSame(1000, $view['profit']['total_cents']);
        $this->assertSame(1, $view['profit']['ready']);
        $rows = app(ReadPanorama::class)->rows()[0];
        $row = collect($rows)->firstWhere('key', 'e'.$this->event->id);
        $this->assertSame($finance->balance_due_at->setTimezone('America/Chicago')->toDateString(), $row['balance_due']);
    }
}
