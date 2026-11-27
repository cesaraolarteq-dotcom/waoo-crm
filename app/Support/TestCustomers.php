<?php
namespace App\Support;

use App\Models\{Customer, Event};

/** Owner 2026-11-23: clients marked as his own tests. Their parties (now and future) and payments stay out of reports. */
final class TestCustomers
{
    public static function ids(): array
    {
        return Customer::where('is_test', true)->pluck('id')->all();
    }

    public static function eventIds(): array
    {
        $ids = self::ids();
        return $ids === [] ? [] : Event::whereIn('customer_id', $ids)->pluck('id')->all();
    }

    /** NOT IN that keeps rows with an empty column (a plain NOT IN would also drop them). */
    public static function exclude($query, string $column, array $ids)
    {
        return $ids === [] ? $query : $query->whereNull($column)->orWhereNotIn($column, $ids);
    }
}
