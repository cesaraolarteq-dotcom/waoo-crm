<?php
namespace App\Models;
class OutboundMessageEvent extends ImmutableRecord
{
    protected $guarded=['id'];protected function casts():array{return ['metadata'=>'array','occurred_at'=>'immutable_datetime'];}
}
