<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnniversaryCode extends Model
{
    protected $fillable = ['customer_id', 'anchor_key', 'anchor_date', 'code', 'state', 'skip_reason', 'issued_at', 'expires_at', 'sent_at',
        'reminder_sent_at', 'redeemed_event_id', 'redeemed_at', 'redeemed_by'];
    protected function casts(): array
    {
        return ['anchor_date' => 'date', 'issued_at' => 'datetime', 'expires_at' => 'datetime', 'sent_at' => 'datetime',
            'reminder_sent_at' => 'datetime', 'redeemed_at' => 'datetime'];
    }
}
