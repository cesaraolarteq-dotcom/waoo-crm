<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportPreviewDelivery extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['lease_token'];
    protected function casts(): array
    {
        return ['attempts' => 'integer', 'available_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }
}
