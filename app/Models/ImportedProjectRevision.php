<?php
namespace App\Models;
class ImportedProjectRevision extends ImmutableRecord { protected $guarded=['id'];protected function casts():array{return ['source_snapshot'=>'array','normalized'=>'array','mapping'=>'array','event_version'=>'integer','revision'=>'integer'];} }
