<?php
namespace App\Models;
class HoneyBookOriginal extends ImmutableRecord {
    protected $table='honeybook_originals';
    protected $guarded=['id'];protected $hidden=['payload','sha256','identity_sha256','project_key'];
    protected function casts():array{return ['payload'=>'encrypted:array'];}
}
