<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WebhookReceipt extends Model
{
    public $incrementing = false; protected $keyType = 'string'; protected $guarded = [];
    protected $hidden = ['encrypted_payload'];
    protected function casts(): array { return ['verified_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime', 'attempts' => 'integer']; }
}
