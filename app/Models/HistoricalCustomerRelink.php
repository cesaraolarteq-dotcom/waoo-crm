<?php
namespace App\Models;
class HistoricalCustomerRelink extends ImmutableRecord
{
    protected $guarded=['id'];
    protected $hidden=['payload','contact_key'];
    protected function casts(): array { return ['payload'=>'encrypted:array','version'=>'integer']; }
}
