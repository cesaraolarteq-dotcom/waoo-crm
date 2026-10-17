<?php
namespace App\Models;

class TravelFeeQuoteRecord extends ImmutableRecord
{
    protected $table = 'travel_fee_quotes';
    protected $guarded = ['id'];
    protected function casts(): array { return ['fee_cents' => 'integer', 'inputs' => 'array', 'rules' => 'array', 'breakdown' => 'array', 'expires_at' => 'immutable_datetime']; }
}
