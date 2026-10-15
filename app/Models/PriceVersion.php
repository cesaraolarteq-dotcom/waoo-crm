<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceVersion extends Model
{
    protected $fillable = ['service_variant_id', 'amount_cents', 'currency', 'created_by'];
    protected function casts(): array { return ['amount_cents' => 'integer']; }
}
