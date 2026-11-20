<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Owner's custom discount for one booking (2026-11-20). */
final class EventDiscount extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['value' => 'integer']; }
}
