<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingEvidence extends Model
{
    protected $fillable = ['event_id', 'method', 'reference', 'amount_cents', 'received_at', 'verified_by','imported_revision_id','received_date','staff_assignment_snapshot'];
    protected function casts(): array { return ['staff_assignment_snapshot'=>'array','imported_revision_id'=>'integer','received_date'=>'immutable_date','amount_cents' => 'integer', 'received_at' => 'immutable_datetime']; }

    protected $table = 'booking_evidence';
}
