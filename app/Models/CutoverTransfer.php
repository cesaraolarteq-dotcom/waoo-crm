<?php
namespace App\Models;
class CutoverTransfer extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['payload' => 'encrypted:array', 'effective_at' => 'immutable_datetime']; }
}
