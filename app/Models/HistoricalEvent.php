<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoricalEvent extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['project_key'];
    protected function casts(): array { return ['version' => 'integer']; }
}
