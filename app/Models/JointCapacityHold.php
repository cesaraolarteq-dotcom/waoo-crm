<?php
namespace App\Models;
class JointCapacityHold extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded=['id'];
    protected $hidden=['payload'];
    protected function casts(): array{return ['payload'=>'array','version'=>'integer','expires_at'=>'immutable_datetime'];}
}
