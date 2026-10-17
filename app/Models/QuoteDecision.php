<?php
namespace App\Models;
class QuoteDecision extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['decided_at'=>'immutable_datetime']; }
}
