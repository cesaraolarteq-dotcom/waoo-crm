<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoMessageLog extends Model
{
    protected $table = 'auto_message_log';
    protected $fillable = ['identity_sha256', 'key', 'channel', 'event_id', 'outbound_message_id', 'sms_message_id', 'status', 'reason'];
}
