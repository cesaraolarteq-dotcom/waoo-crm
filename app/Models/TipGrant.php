<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TipGrant extends Model {
    protected $guarded=['id'];protected $attributes=['version'=>1];protected $hidden=['token_hash','encrypted_token'];
    protected function casts():array{return ['total_cents'=>'integer','version'=>'integer','expires_at'=>'immutable_datetime','revoked_at'=>'immutable_datetime'];}
}
