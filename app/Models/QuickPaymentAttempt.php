<?php
namespace App\Models;
class QuickPaymentAttempt extends ImmutableRecord {
    public $incrementing=false; protected $keyType='string'; protected $guarded=[];
    protected function casts():array{return ['booking_version'=>'integer'];}
}
