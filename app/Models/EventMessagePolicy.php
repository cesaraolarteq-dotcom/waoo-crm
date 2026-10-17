<?php
namespace App\Models;
class EventMessagePolicy extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded=['id'];protected function casts():array{return ['version'=>'integer','paused'=>'boolean'];}
}
