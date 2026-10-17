<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ImportedProjectLink extends Model { protected $guarded=['id'];protected function casts():array{return ['version'=>'integer','current_revision_id'=>'integer'];} }
