<?php
namespace App\Models;
class HoneyBookCutoverReview extends ImmutableRecord
{
    protected $table = 'honeybook_cutover_reviews';
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['version' => 'integer', 'payload' => 'encrypted:array', 'source_observed_at' => 'immutable_datetime']; }
}
