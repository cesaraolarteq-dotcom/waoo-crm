<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ImportFile extends Model
{
    protected $guarded=['id'];protected $hidden=['private_path'];
    protected function casts():array{return ['headers'=>'array','byte_count'=>'integer'];}
}
