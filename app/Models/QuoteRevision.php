<?php
namespace App\Models;

class QuoteRevision extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['revision' => 'integer', 'event_version' => 'integer', 'customer_snapshot' => 'array', 'event_snapshot' => 'array',
            'lines_snapshot' => 'array', 'travel_fee_evidence' => 'array', 'discounts_snapshot' => 'array',
            'subtotal_cents' => 'integer', 'travel_fee_cents' => 'integer', 'discount_cents' => 'integer',
            'total_cents' => 'integer', 'deposit_target_cents' => 'integer', 'valid_until' => 'immutable_datetime'];
    }
}
