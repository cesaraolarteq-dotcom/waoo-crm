<?php
namespace App\Models;
class QuickPaymentEvent extends ImmutableRecord {
    protected $guarded=['id']; protected function casts():array{return ['version'=>'integer','payload'=>'array'];}
}
