<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;

class ZelleNotice extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['ip', 'user_agent'];
    protected function casts(): array { return ['amount_cents' => 'integer', 'sent_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime']; }
    public function review(): HasOne { return $this->hasOne(ZelleNoticeReview::class, 'notice_id'); }
}
