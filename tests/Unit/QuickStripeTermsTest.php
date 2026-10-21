<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\RecordWriteRejected;
use App\Services\Booking\QuickStripeTerms;
use App\Services\Finance\MutationKey;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuickStripeTermsTest extends TestCase
{
    #[DataProvider('money')]
    public function test_actual_principal_and_fee_are_separate_integer_cents(int $total, int $percent, int $principal, int $fee, int $gross): void
    {
        $terms = QuickStripeTerms::freeze($total, new DateTimeImmutable('2026-10-20T12:00:00-05:00'), $percent)->snapshot();
        self::assertSame($principal, $terms['principal_cents']);
        self::assertSame($fee, $terms['customer_fee_cents']);
        self::assertSame($gross, $terms['payable_cents']);
        self::assertSame($total, $terms['total_cents']);
        self::assertSame('USD', $terms['currency']);
    }

    public static function money(): array
    {
        return ['half' => [45000, 50, 22500, 675, 23175], 'full' => [45000, 100, 45000, 1350, 46350],
            'odd principal' => [45001, 50, 22501, 675, 23176], 'half-cent fee' => [100, 50, 50, 2, 52],
            'below half-cent fee' => [98, 50, 49, 1, 50], 'one cent' => [1, 50, 1, 0, 1],
            'provider maximum' => [97087378, 100, 97087378, 2912621, 99999999]];
    }

    #[DataProvider('invalidMoney')]
    public function test_rejects_ambiguous_amounts_and_invalid_provider_or_owner_options(mixed $total, mixed $percent): void
    {
        $this->expectException(InvalidArgumentException::class);
        QuickStripeTerms::freeze($total, new DateTimeImmutable('2026-10-20T17:00:00Z'), $percent);
    }

    public static function invalidMoney(): array
    {
        return [[0, 50], [-1, 50], [true, 50], ['45000', 50], [45000.0, 50], [null, 50],
            [45000, '50'], [45000, 50.0], [45000, true], [45000, 75], [45000, 0],
            [100000000, 50], [97087379, 100], [PHP_INT_MAX, 50]];
    }

    public function test_default_half_and_closed_snapshot_restore_keep_original_terms(): void
    {
        $original = QuickStripeTerms::freeze(45000, new DateTimeImmutable('2026-10-20T12:00:00.999999-05:00'));
        $snapshot = $original->snapshot();
        self::assertSame(50, $snapshot['deposit_percent']);
        $unsigned = $snapshot;
        unset($unsigned['sha256']);
        self::assertSame(MutationKey::hash($unsigned), $snapshot['sha256']);
        self::assertSame($snapshot, QuickStripeTerms::restore(array_reverse($snapshot, true))->snapshot());
        $snapshot['principal_cents'] = 1;
        self::assertSame(22500, $original->snapshot()['principal_cents']);
    }

    #[DataProvider('dstDays')]
    public function test_leases_are_absolute_hours_across_houston_daylight_changes(string $creation, string $localExpiry): void
    {
        $terms = QuickStripeTerms::freeze(45000, new DateTimeImmutable($creation))->snapshot();
        self::assertSame(86400, $terms['reminder_at'] - $terms['created_at']);
        self::assertSame(172800, $terms['expires_at'] - $terms['created_at']);
        self::assertSame($localExpiry, (new DateTimeImmutable('@'.$terms['expires_at']))
            ->setTimezone(new DateTimeZone('America/Chicago'))->format('Y-m-d H:i P'));
    }

    public static function dstDays(): array
    {
        return [['2026-10-31T12:00:00-05:00', '2026-11-02 11:00 -06:00'],
            ['2027-03-13T12:00:00-06:00', '2027-03-15 13:00 -05:00']];
    }

    public function test_checkout_and_restoration_do_not_extend_the_original_lease(): void
    {
        $created = new DateTimeImmutable('2026-10-20T17:00:00Z');
        $terms = QuickStripeTerms::freeze(45000, $created);
        self::assertSame($created->getTimestamp() + 23 * 3600, $terms->checkoutExpiresAt($created));
        $restored = QuickStripeTerms::restore($terms->snapshot());
        self::assertSame($created->getTimestamp() + 48 * 3600, $restored->checkoutExpiresAt($created->modify('+30 hours')));
        self::assertSame($created->getTimestamp() + 48 * 3600, $restored->checkoutExpiresAt($created->modify('+47 hours 29 minutes')));
        self::assertSame($terms->snapshot(), $restored->snapshot());
    }

    #[DataProvider('unusableTimes')]
    public function test_checkout_refuses_clock_regression_and_expired_or_near_expiry_lease(string $offset): void
    {
        $created = new DateTimeImmutable('2026-10-20T17:00:00Z');
        $terms = QuickStripeTerms::freeze(45000, $created);
        try {
            $terms->checkoutExpiresAt($created->modify($offset));
            self::fail('Opened a checkout outside the frozen usable interval.');
        } catch (RecordWriteRejected $e) {
            self::assertSame(409, $e->status);
        }
    }

    public static function unusableTimes(): array
    {
        return [['-1 second'], ['+47 hours 29 minutes 1 second'], ['+48 hours'], ['+49 hours']];
    }

    public function test_every_field_is_closed_typed_and_recomputed_even_if_a_changed_snapshot_is_rehashed(): void
    {
        $terms = QuickStripeTerms::freeze(45000, new DateTimeImmutable('2026-10-20T17:00:00Z'))->snapshot();
        foreach ($terms as $field => $value) {
            $changed = $terms;
            $changed[$field] = is_int($value) ? (string) $value : 1;
            $this->assertInvalid($changed);
            $missing = $terms;
            unset($missing[$field]);
            $this->assertInvalid($missing);
        }
        $this->assertInvalid([...$terms, 'future_column' => 1]);
        foreach (['principal_cents', 'customer_fee_cents', 'payable_cents', 'reminder_at', 'expires_at', 'schema_version'] as $field) {
            $changed = $terms;
            $changed[$field]++;
            unset($changed['sha256']);
            $changed['sha256'] = MutationKey::hash($changed);
            $this->assertInvalid($changed);
        }
    }

    private function assertInvalid(array $snapshot): void
    {
        try {
            QuickStripeTerms::restore($snapshot);
            self::fail('Accepted corrupt terms.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
