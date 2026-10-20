<?php
namespace App\Services\Finance;

/** Frozen V1 allowlist. Invalid provider fields become null, never arbitrary strings, contact data or zero. */
final class RefundProviderFacts
{
    public static function read(array $source): array
    {
        $out = ['account' => self::id($source['account'] ?? null, 'acct')];
        foreach (['refund' => ['id','status','amount','currency','created','charge_id','payment_id'],
            'charge' => ['id','payment_id','amount','currency','created','livemode','paid','captured'],
            'payment' => ['id','amount_received','currency','livemode','status']] as $part => $fields) {
            $row = is_array($source[$part] ?? null) ? $source[$part] : []; $out[$part] = [];
            foreach ($fields as $field) {
                $v = $row[$field] ?? null;
                $out[$part][$field] = match ($field) {
                    'id' => self::id($v, ['refund' => 're', 'charge' => 'ch', 'payment' => 'pi'][$part]),
                    'charge_id' => self::id($v, 'ch'), 'payment_id' => self::id($v, 'pi'),
                    'amount', 'amount_received' => is_int($v) && $v > 0 && $v <= 100000000 ? $v : null,
                    'created' => is_int($v) && $v > 0 && $v <= 4102444800 ? $v : null,
                    'currency' => is_string($v) && preg_match('/^[a-z]{3}$/D', $v) ? $v : null,
                    'livemode', 'paid', 'captured' => is_bool($v) ? $v : null,
                    'status' => is_string($v) && in_array($v, ['pending','requires_action','succeeded','failed','canceled'], true) ? $v : null,
                };
            }
        }
        return $out;
    }
    public static function valid(array $p, array $callback): bool
    {
        $r = $p['refund']; $c = $p['charge']; $i = $p['payment'];
        foreach ([$r, $c, $i] as $row) if (in_array(null, $row, true)) return false;
        return self::validCharge($p, $c['id']) && $r['id'] === ($callback['id'] ?? null) && $r['charge_id'] === $c['id'] && $r['payment_id'] === $c['payment_id'] && $r['payment_id'] === $i['id']
            && (($callback['charge'] ?? null) === null || $callback['charge'] === $c['id'])
            && (($callback['payment_intent'] ?? null) === null || $callback['payment_intent'] === $i['id'])
            && $r['currency'] === 'usd' && $c['currency'] === 'usd' && $i['currency'] === 'usd'
            && $c['livemode'] === false && $i['livemode'] === false && $c['captured'] === true && $c['paid'] === true && $i['status'] === 'succeeded'
            && $i['amount_received'] === $c['amount'] && $r['amount'] <= $c['amount']
            && $r['created'] >= $c['created'] && $r['created'] <= now()->timestamp;
    }
    public static function validCharge(array $p, string $chargeId): bool
    {
        $c = $p['charge']; $i = $p['payment'];
        if (in_array(null, $c, true) || in_array(null, $i, true)) return false;
        return $p['account'] !== null && $p['account'] === config('waoo.stripe_test_account') && $c['id'] === $chargeId
            && $c['payment_id'] === $i['id'] && $c['currency'] === 'usd' && $i['currency'] === 'usd'
            && $c['livemode'] === false && $i['livemode'] === false && $c['paid'] === true && $c['captured'] === true
            && $i['status'] === 'succeeded' && $c['amount'] === $i['amount_received'] && $c['created'] <= now()->timestamp;
    }
    private static function id(mixed $value, string $prefix): ?string
    { return is_string($value) && preg_match('/^'.$prefix.'_[A-Za-z0-9_]{1,200}$/D', $value) ? $value : null; }
}
