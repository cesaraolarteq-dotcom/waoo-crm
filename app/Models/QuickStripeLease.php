<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class QuickStripeLease extends Model
{
    protected $guarded=['id'];protected $hidden=['payload'];
    protected function casts():array{return ['version'=>'integer','expires_at'=>'immutable_datetime','payload'=>'encrypted:array'];}
    protected static function booted():void{static::deleting(fn()=>throw new \LogicException('Unpaid lease history cannot be deleted.'));}
}
