<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['provider_at' => 'immutable_datetime'];
    }

    public function customer() { return $this->belongsTo(Customer::class); }
}
