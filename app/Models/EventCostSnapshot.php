<?php
namespace App\Models;

class EventCostSnapshot extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['event_version' => 'integer', 'revision' => 'integer', 'lines_snapshot' => 'array', 'known_cost_cents' => 'integer',
            'unknown_line_count' => 'integer', 'coverage_confirmed' => 'boolean', 'complete' => 'boolean'];
    }
}
