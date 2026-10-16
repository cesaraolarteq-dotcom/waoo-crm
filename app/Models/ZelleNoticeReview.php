<?php
namespace App\Models;

class ZelleNoticeReview extends ImmutableRecord
{
    protected $guarded = ['id'];
    public function ledgerEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(LedgerEntry::class); }
    protected function casts(): array { return ['reviewed_at' => 'immutable_datetime']; }
}
