<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffPayLine extends Model
{
    protected $guarded = [];

    protected $casts = ['paid_at' => 'immutable_datetime'];
}
