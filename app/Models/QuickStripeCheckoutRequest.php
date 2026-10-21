<?php

namespace App\Models;

class QuickStripeCheckoutRequest extends ImmutableRecord
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['envelope'];

    protected function casts(): array
    {
        return ['envelope' => 'encrypted:array'];
    }
}
