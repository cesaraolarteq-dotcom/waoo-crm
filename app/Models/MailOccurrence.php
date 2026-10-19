<?php
namespace App\Models;

class MailOccurrence extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['locator', 'dedupe_sha256'];
    protected function casts(): array { return ['locator' => 'encrypted:array', 'occurred_at' => 'immutable_datetime']; }
}
