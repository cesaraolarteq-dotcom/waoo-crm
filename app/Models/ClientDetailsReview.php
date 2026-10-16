<?php
namespace App\Models;

class ClientDetailsReview extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['event_version' => 'integer', 'travel_fee_confirmed' => 'boolean', 'reviewed_at' => 'immutable_datetime']; }
}
