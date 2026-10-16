<?php
namespace App\Models;

class LeadSubmission extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['payload' => 'array', 'submitted_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime']; }
}
