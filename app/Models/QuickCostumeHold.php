<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class QuickCostumeHold extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['payload' => 'encrypted:array', 'version' => 'integer']; }
    protected static function booted(): void { static::deleting(fn () => throw new \LogicException('Hold history cannot be deleted.')); }
}
