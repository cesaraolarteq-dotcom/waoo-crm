<?php
declare(strict_types=1);

namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Services\Finance\MutationKey;
use DateTimeImmutable;
use InvalidArgumentException;

/** Frozen monetary/deadline terms only; never evidence of payment or inventory. */
final readonly class QuickStripeTerms
{
    private const MAX_AMOUNT = 99_999_999;
    private const KEYS = ['schema_version', 'total_cents', 'deposit_percent', 'principal_cents',
        'customer_fee_cents', 'payable_cents', 'currency', 'created_at', 'reminder_at', 'expires_at', 'sha256'];

    private function __construct(private array $terms) {}

    public static function freeze(mixed $totalCents, DateTimeImmutable $createdAt, mixed $depositPercent = 50): self
    {
        if (!is_int($totalCents) || $totalCents < 1 || $totalCents > self::MAX_AMOUNT
            || !in_array($depositPercent, [50, 100], true)) {
            throw new InvalidArgumentException('Invalid quick Stripe monetary terms.');
        }
        $created = $createdAt->getTimestamp();
        if ($created < 0 || $created > PHP_INT_MAX - 172800) {
            throw new InvalidArgumentException('Invalid quick Stripe creation time.');
        }
        $principal = $depositPercent === 100 ? $totalCents : intdiv($totalCents + 1, 2);
        $fee = intdiv($principal * 3 + 50, 100);
        $payable = $principal + $fee;
        if ($payable > self::MAX_AMOUNT) {
            throw new InvalidArgumentException('Quick Stripe payable exceeds the provider amount limit.');
        }
        $terms = ['schema_version' => 1, 'total_cents' => $totalCents, 'deposit_percent' => $depositPercent,
            'principal_cents' => $principal, 'customer_fee_cents' => $fee, 'payable_cents' => $payable,
            'currency' => 'USD', 'created_at' => $created, 'reminder_at' => $created + 86400,
            'expires_at' => $created + 172800];
        return new self([...$terms, 'sha256' => MutationKey::hash($terms)]);
    }

    public static function restore(array $snapshot): self
    {
        if (count($snapshot) !== count(self::KEYS) || array_diff(self::KEYS, array_keys($snapshot)) !== []
            || !is_int($snapshot['created_at'] ?? null)) {
            throw new InvalidArgumentException('Invalid quick Stripe terms snapshot.');
        }
        $restored = self::freeze($snapshot['total_cents'], new DateTimeImmutable('@'.$snapshot['created_at']), $snapshot['deposit_percent']);
        foreach ($restored->terms as $field => $value) {
            if ($snapshot[$field] !== $value) {
                throw new InvalidArgumentException('Quick Stripe terms integrity mismatch.');
            }
        }
        return $restored;
    }

    public function snapshot(): array
    {
        return $this->terms;
    }

    /** Call once before persisting a NEW provider request; never recompute on retry. */
    public function checkoutExpiresAt(DateTimeImmutable $now): int
    {
        $instant = $now->getTimestamp();
        if ($instant < $this->terms['created_at'] || $instant > $this->terms['expires_at'] - 1860) {
            throw new RecordWriteRejected(409, 'No queda tiempo suficiente para abrir otro cobro. Revisa el apartado.');
        }
        return $instant + min(82800, $this->terms['expires_at'] - $instant);
    }
}
