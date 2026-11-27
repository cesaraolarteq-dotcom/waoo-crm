<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleContactConnection extends Model
{
    protected $fillable = ['user_id', 'google_email', 'refresh_token', 'access_token', 'access_expires_at', 'group_resource_name', 'last_error'];
    protected $hidden = ['refresh_token', 'access_token'];
    protected function casts(): array { return ['refresh_token' => 'encrypted', 'access_token' => 'encrypted', 'access_expires_at' => 'immutable_datetime']; }
}
