<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class StaffIdentity extends Model
{
    protected $guarded = ['id'];
    protected $attributes = ['version'=>1];
    protected $hidden = ['phone'];
    protected function casts(): array { return ['active'=>'boolean','version'=>'integer','phone'=>'encrypted']; }
}
