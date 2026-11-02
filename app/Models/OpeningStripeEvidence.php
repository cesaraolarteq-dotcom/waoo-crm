<?php
namespace App\Models;
class OpeningStripeEvidence extends ImmutableRecord
{
    protected $table = 'opening_stripe_evidence';
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['payload' => 'encrypted:array']; }
}
