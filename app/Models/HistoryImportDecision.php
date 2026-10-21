<?php
namespace App\Models;

class HistoryImportDecision extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['source_line' => 'integer', 'payload' => 'encrypted:array']; }
}
