<?php
namespace App\Models;

class HistoricalEventRevision extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['version' => 'integer', 'payload' => 'encrypted:array']; }
}
