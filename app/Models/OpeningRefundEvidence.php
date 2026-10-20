<?php
namespace App\Models;
class OpeningRefundEvidence extends ImmutableRecord
{
    protected $table = 'opening_refund_evidence';
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['payload' => 'encrypted:array']; }
}
