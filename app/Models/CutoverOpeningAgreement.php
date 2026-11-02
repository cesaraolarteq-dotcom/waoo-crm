<?php
namespace App\Models;

class CutoverOpeningAgreement extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['payload' => 'encrypted:array']; }
}
