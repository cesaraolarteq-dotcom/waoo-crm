<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TipCheckoutIntent extends Model {
    public $incrementing=false;protected $keyType='string';protected $guarded=[];protected $hidden=['checkout_url'];
    protected function casts():array{return ['amount_cents'=>'integer','checkout_url'=>'encrypted','expires_at'=>'immutable_datetime'];}
}
