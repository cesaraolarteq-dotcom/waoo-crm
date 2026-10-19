<?php
namespace App\Models;
final class StaffJobInstructions extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['payload'=>'encrypted:array','version'=>'integer']; }
}
