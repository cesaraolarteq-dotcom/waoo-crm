<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class ImmutableRecord extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Use a new revision or linked correction; historical records are immutable.'));
        static::deleting(fn () => throw new LogicException('Historical financial/document evidence cannot be deleted.'));
    }
}
