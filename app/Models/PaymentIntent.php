<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PaymentIntent extends Model
{
    public $incrementing = false; protected $keyType = 'string'; protected $guarded = [];
    protected function casts(): array { return ['principal_cents' => 'integer', 'customer_fee_cents' => 'integer', 'payable_cents' => 'integer', 'expires_at' => 'immutable_datetime']; }
}
