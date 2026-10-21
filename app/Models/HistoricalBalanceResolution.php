<?php
namespace App\Models;
class HistoricalBalanceResolution extends ImmutableRecord {
    protected $guarded=['id'];protected $hidden=['payload','sha256'];
    protected function casts():array{return ['version'=>'integer','payload'=>'encrypted:array'];}
}
