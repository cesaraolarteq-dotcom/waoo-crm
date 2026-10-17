<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ImportServiceMapping extends Model { protected $guarded=['id'];protected function casts():array{return ['selection'=>'array','version'=>'integer'];} }
