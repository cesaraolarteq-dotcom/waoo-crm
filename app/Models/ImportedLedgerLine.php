<?php
namespace App\Models;
class ImportedLedgerLine extends ImmutableRecord { protected $guarded=['id'];protected function casts():array{return ['amount_cents'=>'integer','received_date'=>'immutable_date','source_position'=>'integer'];} }
