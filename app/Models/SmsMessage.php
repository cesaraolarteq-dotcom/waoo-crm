<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One text sent to or received from a client through Twilio. */
class SmsMessage extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['body'];

    protected function casts(): array { return ['body' => 'encrypted']; }
}
