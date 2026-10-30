<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventChange extends Model
{
    protected $fillable = ['event_id', 'kind', 'state', 'old_starts_at', 'old_ends_at', 'new_starts_at', 'new_ends_at', 'event_version_before', 'event_version_after',
        'fee_decision', 'fee_reason', 'fee_cents', 'moved_reservations', 'contract_id', 'notified_at', 'reminded_at', 'created_by'];
    protected function casts(): array
    {
        return ['old_starts_at' => 'immutable_datetime', 'old_ends_at' => 'immutable_datetime', 'new_starts_at' => 'immutable_datetime', 'new_ends_at' => 'immutable_datetime',
            'notified_at' => 'datetime', 'reminded_at' => 'datetime', 'fee_cents' => 'integer', 'moved_reservations' => 'integer'];
    }
}
