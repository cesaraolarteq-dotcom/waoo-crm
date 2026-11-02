<?php
namespace Tests\Unit;

use App\Services\Finance\OpeningBalance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OpeningBalanceTest extends TestCase
{
    private function opening(array $change = []): array
    {
        return ['original_total_cents' => 12000, 'source_gross_received_cents' => 7000,
            'source_refund_cents' => 1000, 'source_net_received_cents' => 6000, ...$change];
    }
    public function test_source_receipts_adjustments_and_new_crm_cash_are_separate(): void
    {
        $v = OpeningBalance::calculate($this->opening(), [['kind' => 'receipt', 'amount_cents' => 4000], ['kind' => 'refund', 'amount_cents' => 500]]);
        $this->assertSame([7000, 1000, 6000, 4000, 500, 9500, 2500, 0], array_map(fn ($k) => $v[$k], [
            'source_gross_received_cents', 'source_refund_cents', 'source_net_received_cents', 'crm_received_cents', 'crm_refunded_cents',
            'net_received_cents', 'balance_cents', 'credit_cents']));
    }
    public function test_source_overpayment_and_crm_refund_of_source_funds_are_not_lost(): void
    {
        $v = OpeningBalance::calculate($this->opening(['source_gross_received_cents' => 14000, 'source_net_received_cents' => 13000]));
        $this->assertSame([-1000, 0, 1000], [$v['raw_balance_cents'], $v['balance_cents'], $v['credit_cents']]);
        $v = OpeningBalance::calculate($this->opening(), [['kind' => 'refund', 'amount_cents' => 6500]]);
        $this->assertSame(-500, $v['net_received_cents']); $this->assertSame(12500, $v['balance_cents']);
    }
    public static function bad(): array
    {
        return [
            'float' => [['source_net_received_cents' => 6000.0], []],
            'numeric string' => [['original_total_cents' => '12000'], []],
            'negative source' => [['source_net_received_cents' => -1], []],
            'wrong source sum' => [['source_net_received_cents' => 7000], []],
            'oversize' => [['original_total_cents' => PHP_INT_MAX], []],
            'unknown source key' => [['tip_cents' => 100], []],
            'tip mixed in' => [[], [['kind' => 'tip', 'amount_cents' => 100]]],
            'zero movement' => [[], [['kind' => 'receipt', 'amount_cents' => 0]]],
            'negative movement' => [[], [['kind' => 'refund', 'amount_cents' => -100]]],
            'boolean movement' => [[], [['kind' => 'receipt', 'amount_cents' => true]]],
            'unknown movement key' => [[], [['kind' => 'receipt', 'amount_cents' => 100, 'verified' => true]]],
        ];
    }
    #[DataProvider('bad')]
    public function test_unsafe_values_and_tip_inputs_fail_closed(array $change, array $movements): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpeningBalance::calculate($this->opening($change), $movements);
    }
}
