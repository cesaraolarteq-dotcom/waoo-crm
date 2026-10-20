<?php
namespace App\Models;
class QuickStripeLeaseEvent extends ImmutableRecord
{
    protected $guarded=['id'];protected $hidden=['payload'];
    protected function casts():array{return ['version'=>'integer','payload'=>'encrypted:array'];}
}
