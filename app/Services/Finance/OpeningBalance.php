<?php
namespace App\Services\Finance;

use InvalidArgumentException;

/** Pure arithmetic, never receipt verification, allocation or collection authority. */
final class OpeningBalance
{
    public static function calculate(array $opening, array $movements = []): array
    {
        $fields = ['original_total_cents', 'source_gross_received_cents', 'source_refund_cents', 'source_net_received_cents'];
        self::check(count($opening) === count($fields) && array_diff(array_keys($opening), $fields) === []);
        foreach ($fields as $field) self::check(is_int($opening[$field] ?? null) && $opening[$field] >= 0 && $opening[$field] <= 100000000000);
        self::check($opening['source_gross_received_cents'] - $opening['source_refund_cents'] === $opening['source_net_received_cents']);
        self::check(array_is_list($movements) && count($movements) <= 10000);
        $receipts = $refunds = 0;
        foreach ($movements as $m) {
            self::check(is_array($m) && count($m) === 2 && array_diff(array_keys($m), ['kind', 'amount_cents']) === []
                && in_array($m['kind'] ?? null, ['receipt', 'refund'], true) && is_int($m['amount_cents'] ?? null)
                && $m['amount_cents'] > 0 && $m['amount_cents'] <= 100000000000);
            if ($m['kind'] === 'receipt') $receipts += $m['amount_cents']; else $refunds += $m['amount_cents'];
        }
        $net = $opening['source_net_received_cents'] + $receipts - $refunds;
        $raw = $opening['original_total_cents'] - $net;
        return [...$opening, 'crm_received_cents' => $receipts, 'crm_refunded_cents' => $refunds,
            'net_received_cents' => $net, 'raw_balance_cents' => $raw, 'balance_cents' => max(0, $raw), 'credit_cents' => max(0, -$raw)];
    }
    private static function check(bool $valid): void
    {
        if (!$valid) throw new InvalidArgumentException('Invalid opening arithmetic input.');
    }
}
