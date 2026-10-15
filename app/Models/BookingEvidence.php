<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingEvidence extends Model
{
    protected $fillable = ['event_id', 'method', 'reference', 'amount_cents', 'received_at', 'verified_by'];
    protected function casts(): array { return ['amount_cents' => 'integer', 'received_at' => 'immutable_datetime']; }

    protected $table = 'booking_evidence';
}
