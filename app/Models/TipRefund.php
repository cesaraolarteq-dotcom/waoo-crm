<?php
namespace App\Models;
class TipRefund extends ImmutableRecord {
    protected $guarded=['id'];protected function casts():array{return ['amount_cents'=>'integer','received_at'=>'immutable_datetime'];}
}
