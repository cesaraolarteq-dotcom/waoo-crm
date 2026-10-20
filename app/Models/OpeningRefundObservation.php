<?php
namespace App\Models;
class OpeningRefundObservation extends ImmutableRecord
{
    protected $table = 'opening_refund_observations';
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['payload' => 'encrypted:array']; }
}
