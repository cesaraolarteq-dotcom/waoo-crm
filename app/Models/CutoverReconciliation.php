<?php
namespace App\Models;
class CutoverReconciliation extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['payload' => 'encrypted:array', 'source_observed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'policy_max_age_minutes' => 'integer']; }
}
