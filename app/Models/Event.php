<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = ['customer_id', 'title', 'event_type', 'starts_at', 'ends_at', 'timezone', 'address', 'postal_code', 'setup_minutes', 'breakdown_minutes', 'travel_fee_cents'];
    protected function casts(): array { return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'version' => 'integer', 'travel_fee_cents' => 'integer', 'setup_minutes' => 'integer', 'breakdown_minutes' => 'integer']; }

    public function services() { return $this->hasMany(EventService::class); }
    public function reservations() { return $this->hasMany(ResourceReservation::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
}
