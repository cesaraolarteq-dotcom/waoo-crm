<?php
namespace App\Models;
class OutboundMessage extends ImmutableRecord
{
    protected $guarded=['id'];protected $hidden=['payload','recipient_sha256','intent_sha256','source_sha256'];
    protected function casts():array{return ['payload'=>'encrypted:array','due_at'=>'immutable_datetime','event_version'=>'integer','customer_version'=>'integer'];}
}
