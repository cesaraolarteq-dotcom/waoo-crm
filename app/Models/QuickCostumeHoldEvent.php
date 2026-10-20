<?php
namespace App\Models;
class QuickCostumeHoldEvent extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['payload' => 'encrypted:array', 'version' => 'integer']; }
}
