<?php
namespace App\Models;
class QuickPaymentNotification extends \Illuminate\Database\Eloquent\Model {
    protected $guarded=['id']; protected $hidden=['payload'];
    protected static function booted():void {static::updating(function(self $row){if(array_intersect(array_keys($row->getDirty()),['quick_payment_booking_id','identity_sha256','kind','payload','payload_sha256','due_at']))throw new \LogicException('Notification source is immutable.');});}
    protected function casts():array{return ['payload'=>'encrypted:array','due_at'=>'immutable_datetime'];}
}
