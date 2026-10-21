<?php
namespace App\Models;
class QuickPaymentReceipt extends ImmutableRecord {
    protected $guarded=['id']; protected $hidden=['verification'];
    protected function casts():array{return ['verification'=>'encrypted:array','principal_cents'=>'integer','customer_fee_cents'=>'integer','gross_cents'=>'integer'];}
}
