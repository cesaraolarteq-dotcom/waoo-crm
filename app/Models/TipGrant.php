<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TipGrant extends Model {
    protected $guarded=['id','snapshot_schema_version'];protected $attributes=['version'=>1,'snapshot_schema_version'=>1];protected $hidden=['token_hash','encrypted_token'];
    protected function casts():array{return ['snapshot_schema_version'=>'integer','total_cents'=>'integer','version'=>'integer','expires_at'=>'immutable_datetime','revoked_at'=>'immutable_datetime'];}
}
