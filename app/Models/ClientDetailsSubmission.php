<?php
namespace App\Models;

class ClientDetailsSubmission extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['base_customer_snapshot', 'ip', 'user_agent'];
    protected function casts(): array { return ['data' => 'array', 'base_customer_snapshot' => 'array', 'revision' => 'integer', 'event_version' => 'integer', 'submitted_at' => 'immutable_datetime']; }
}
