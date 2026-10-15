<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalReview extends Model
{
    protected $fillable = ['event_id', 'reviewed_by', 'evidence'];
    protected function casts(): array { return ['evidence' => 'array']; }
}
