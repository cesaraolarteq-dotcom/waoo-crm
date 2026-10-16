<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBookingRecord extends Model
{
    protected $table = 'quick_bookings';
    protected $guarded = ['id'];
    protected function casts(): array { return ['portal_expires_at' => 'immutable_datetime']; }
    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Quick booking financial history cannot be deleted.'));
        static::updating(function (self $row): void {
            if (array_intersect(array_keys($row->getDirty()), ['event_id','customer_id','initial_financial_revision_id','receipt_id','created_by'])) {
                throw new \LogicException('Quick booking financial linkage is immutable.');
            }
        });
    }
}
