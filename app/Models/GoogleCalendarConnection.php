<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleCalendarConnection extends Model
{
    protected $fillable = ['user_id', 'google_email', 'refresh_token', 'access_token', 'access_expires_at', 'calendar_id', 'last_error'];
    protected $hidden = ['refresh_token', 'access_token'];
    protected function casts(): array { return ['refresh_token' => 'encrypted', 'access_token' => 'encrypted', 'access_expires_at' => 'immutable_datetime']; }
}
