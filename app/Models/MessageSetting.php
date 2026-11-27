<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageSetting extends Model
{
    protected $fillable = ['key', 'email_enabled', 'sms_enabled', 'email_approved_sha256', 'sms_approved_sha256', 'updated_by', 'version'];
    protected function casts(): array { return ['email_enabled' => 'boolean', 'sms_enabled' => 'boolean', 'version' => 'integer']; }
}
