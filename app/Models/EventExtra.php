<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventExtra extends Model
{
    protected $guarded = [];

    protected $casts = ['accepted_at' => 'immutable_datetime'];
}
