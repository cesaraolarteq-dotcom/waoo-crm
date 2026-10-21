<?php
namespace App\Models;
class HoneyBookEventFacts extends ImmutableRecord {
    protected $table='honeybook_event_facts';
    protected $guarded=['id'];protected $hidden=['payload','sha256'];
    protected function casts():array{return ['version'=>'integer','payload'=>'encrypted:array'];}
}
