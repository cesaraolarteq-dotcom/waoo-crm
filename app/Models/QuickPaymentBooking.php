<?php
namespace App\Models;
class QuickPaymentBooking extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = ['id']; protected $hidden = ['agreement'];
    protected function casts(): array { return ['version'=>'integer','agreement'=>'encrypted:array','expires_at'=>'immutable_datetime','reminder_at'=>'immutable_datetime']; }
    protected static function booted(): void
    {
        static::deleting(fn()=>throw new \LogicException('Quick payment history cannot be deleted.'));
        static::updating(function(self $row) {
            if ($row->getRawOriginal('agreement_sha256') !== null && array_intersect(array_keys($row->getDirty()),
                ['event_id','customer_id','financial_revision_id','quick_stripe_lease_id','portal_grant_id','created_by','agreement','agreement_sha256','expires_at','reminder_at'])) {
                throw new \LogicException('Quick payment agreement is immutable.');
            }
        });
    }
}
