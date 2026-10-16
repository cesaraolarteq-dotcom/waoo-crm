<?php
namespace App\Models;

class FinancialRevision extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['lines_snapshot' => 'array', 'revision' => 'integer', 'event_version' => 'integer', 'subtotal_cents' => 'integer',
            'travel_fee_cents' => 'integer', 'tax_cents' => 'integer', 'customer_fee_cents' => 'integer', 'total_cents' => 'integer',
            'deposit_target_cents' => 'integer', 'balance_due_at' => 'immutable_datetime'];
    }
}
