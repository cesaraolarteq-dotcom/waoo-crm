<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Whether one phone number agreed to texts from Waoo (website checkbox) or asked to stop (STOP). */
class SmsConsent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array { return ['changed_at' => 'immutable_datetime']; }

    public static function allows(string $e164): bool
    {
        return self::where('phone_e164', $e164)->value('status') === 'opted_in';
    }
}
