<?php
namespace Tests\Unit;

use App\Services\Reports\Panorama;
use PHPUnit\Framework\TestCase;

// Panorama (César 2026-10-28): any window compared with the same dates a year earlier; the pulse counts booking
// deposits only; real profit leaves out travel fee and unpaid owner transport.
class PanoramaTest extends TestCase
{
    private function party(string $key, string $date, ?int $total, array $over = []): array
    {
        return ['key' => $key, 'date' => $date, 'total_cents' => $total, 'zip' => '77494', 'city' => 'Katy', 'source' => 'WhatsApp', 'booked_on' => null,
            'customer_id' => null, 'name' => 'María González', 'balance_cents' => null, 'balance_due' => null, 'profit' => null, ...$over];
    }

    public function test_windows(): void
    {
        $this->assertSame(['2026-01-01', '2026-12-31'], Panorama::window('year', '2026-04-10'));
        $this->assertSame(['2026-04-01', '2026-04-30'], Panorama::window('month', '2026-04-10'));
        $this->assertSame(['2026-04-06', '2026-04-12'], Panorama::window('week', '2026-04-10'));
        $this->assertSame(['2026-03-01', '2026-05-31'], Panorama::window('custom', '2026-04-10', '2026-05-31', '2026-03-01'));
    }

    public function test_kpis_compare_with_last_year_and_keep_unknown_totals_unknown(): void
    {
        $parties = [
            $this->party('a', '2026-04-04', 40000, ['source' => 'Instagram']),
            $this->party('b', '2026-04-11', 60000),
            $this->party('c', '2026-04-18', null),
            $this->party('d', '2025-04-12', 50000),
        ];
        $payments = [
            ['date' => '2026-03-02', 'amount_cents' => 20000, 'deposit' => true],
            ['date' => '2026-04-03', 'amount_cents' => 20000, 'deposit' => false],
            ['date' => '2026-04-06', 'amount_cents' => 30000, 'deposit' => true],
        ];
        $p = (new Panorama)->build($parties, $payments, [['date' => '2026-04-02', 'status' => 'booked', 'reason' => null], ['date' => '2026-04-05', 'status' => 'lost', 'reason' => 'price']], '2026-04-01', '2026-04-30', '2026-04-20');
        $this->assertSame(['value' => 3, 'previous' => 1, 'change_pct' => 200, 'unknown' => 0], $p['kpis']['parties']);
        $this->assertSame(100000, $p['kpis']['sales']['value']);
        $this->assertSame(1, $p['kpis']['sales']['unknown']);
        $this->assertSame(50000, $p['kpis']['ticket']['value']);
        $this->assertSame(50000, $p['kpis']['received']['value'], 'Every payment counts as money received.');
        $this->assertSame(1, $p['pulse']['count'], 'Only the April deposit counts in the pulse.');
        $this->assertSame(1, $p['pulse']['weekday'][1]);
        $this->assertSame('6–10', $p['pulse']['strongest_days']);
        $this->assertSame(2, $p['kpis']['leads']['value']);
        $this->assertSame(['count' => 2, 'booked' => 1, 'lost' => 1, 'open' => 0, 'conversion_pct' => 50, 'reasons' => ['Precio' => 1]], $p['funnel']);
        $april4 = collect($p['sales']['buckets'])->firstWhere('start', '2026-04-04');
        $this->assertSame(1, $april4['parties']);
        $this->assertSame('María González', $april4['items'][0]['name']);
        $this->assertSame(1, collect($p['sales']['buckets'])->firstWhere('start', '2026-04-12')['last_year'], 'Last year\'s Apr 12 party sits on this year\'s Apr 12.');
        $this->assertSame('day', $p['sales']['unit']);
        $this->assertSame(['WhatsApp', 2, 60000], [$p['channels'][0]['label'], $p['channels'][0]['parties'], $p['channels'][0]['average_cents']]);
        $this->assertSame(['77494', 3], [$p['zones'][0]['zip'], $p['zones'][0]['parties']]);
    }

    public function test_booking_window_profit_and_planning_lists(): void
    {
        $parties = [
            $this->party('h1', '2025-10-25', 40000, ['booked_on' => '2025-10-11', 'customer_id' => 1, 'name' => 'Sofía Méndez']),
            $this->party('h2', '2025-11-15', 40000, ['booked_on' => '2025-10-04', 'customer_id' => 2, 'name' => 'Lucía Ramírez']),
            $this->party('h3', '2025-11-22', 40000, ['customer_id' => 3, 'name' => 'Ana Torres']),
            $this->party('n1', '2026-10-31', 50000, ['customer_id' => 3, 'balance_cents' => 25000, 'balance_due' => '2026-10-30',
                'profit' => ['revenue_cents' => 45000, 'cost_cents' => 21000]]),
            $this->party('n2', '2026-11-07', 50000, ['balance_cents' => 0]),
        ];
        $p = (new Panorama)->build($parties, [], [], '2026-10-01', '2026-10-31', '2026-10-20');
        $halloween = collect($p['booking_window'])->firstWhere('season', 'halloween');
        $this->assertSame([1, 2.0], [$halloween['known'], $halloween['median_weeks']]);
        $this->assertSame(['ready' => 1, 'pending' => 0, 'total_cents' => 24000, 'average_cents' => 24000], $p['profit']);
        $this->assertSame(25000, $p['ahead']['receivable']['cents']);
        $this->assertSame([['date' => '2026-10-24', 'parties' => 0], ['date' => '2026-10-31', 'parties' => 1]], array_slice($p['ahead']['saturdays'], 0, 2));
        $this->assertSame('2026-11', $p['ahead']['anniversary']['month']);
        $this->assertSame([['name' => 'Lucía Ramírez', 'date' => '2025-11-15', 'rebooked' => false], ['name' => 'Ana Torres', 'date' => '2025-11-22', 'rebooked' => true]],
            $p['ahead']['anniversary']['items']);
    }

    public function test_growth_compares_each_month_with_last_year_and_the_current_month_only_to_the_same_day(): void
    {
        $parties = [
            $this->party('a', '2026-03-14', 50000), $this->party('b', '2025-03-08', 40000),
            $this->party('c', '2026-11-07', 60000), $this->party('d', '2025-11-08', 30000), $this->party('e', '2025-11-22', 30000),
            $this->party('f', '2025-12-12', 45000), $this->party('g', '2026-12-05', 45000),
        ];
        $payments = [['date' => '2026-11-02', 'amount_cents' => 30000, 'deposit' => true], ['date' => '2025-11-03', 'amount_cents' => 15000, 'deposit' => true]];
        $g = (new Panorama)->build($parties, $payments, [], '2026-01-01', '2026-12-31', '2026-11-15')['growth'];
        $mar = $g['months'][2]; $nov = $g['months'][10]; $dec = $g['months'][11];
        $this->assertSame(['closed', 1, 1, 25], [$mar['state'], $mar['parties'], $mar['ly_parties'], $mar['sales_pct']]);
        // November 1-15 2026 against November 1-15 2025: the Nov 22, 2025 party is not compared yet.
        $this->assertSame(['current', 15, 1, 1, 100, 30000, 15000], [$nov['state'], $nov['until_day'], $nov['parties'], $nov['ly_parties'], $nov['sales_pct'], $nov['received_cents'], $nov['ly_received_cents']]);
        // December is ahead: the booked 2026 party is not counted as done; last year is the target.
        $this->assertSame(['ahead', null, 1], [$dec['state'], $dec['parties'], $dec['ly_parties']]);
        $this->assertSame([2, 2, 110000, 70000], [$g['ytd']['parties'], $g['ytd']['ly_parties'], $g['ytd']['sales_cents'], $g['ytd']['ly_sales_cents']]);
        $this->assertSame('Noviembre', $g['best']['label']);
    }
}
