<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ImportBatch extends Model
{
    protected $guarded=['id'];
    protected $hidden=['active_fingerprint'];
    protected function casts():array{return ['version'=>'integer','profile'=>'array','reconciliation'=>'array','received_at'=>'immutable_datetime'];}
}
