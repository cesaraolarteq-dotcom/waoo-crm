<?php
namespace App\Models;
class JointCapacityHoldEvent extends ImmutableRecord
{
    protected $guarded=['id'];
    protected $hidden=['payload'];
    protected function casts(): array{return ['payload'=>'array','version'=>'integer'];}
}
