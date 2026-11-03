<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventTeamPlan extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['slots' => 'array', 'costumes' => 'array', 'reservation_ids' => 'array', 'external_driver' => 'boolean', 'version' => 'integer'];
    }
}
