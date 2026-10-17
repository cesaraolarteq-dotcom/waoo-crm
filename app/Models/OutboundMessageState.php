<?php
namespace App\Models;
class OutboundMessageState extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded=['id'];
    protected function casts():array{return ['version'=>'integer','attempt_count'=>'integer','sent_at'=>'immutable_datetime','delivered_at'=>'immutable_datetime'];}
}
