<?php
namespace App\Models;
class TipReceipt extends ImmutableRecord {
    protected $guarded=['id'];
    protected function casts():array{return ['gross_cents'=>'integer','processing_fee_cents'=>'integer','received_at'=>'immutable_datetime'];}
}
