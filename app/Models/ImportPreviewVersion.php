<?php
namespace App\Models;
class ImportPreviewVersion extends ImmutableRecord
{
    protected $guarded=['id'];protected function casts():array{return ['counts'=>'array','version'=>'integer'];}
}
