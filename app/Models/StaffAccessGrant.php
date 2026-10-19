<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class StaffAccessGrant extends Model
{
    protected $guarded = ['id'];
    protected $attributes = ['version'=>1];
    protected $hidden = ['token_hash','encrypted_token'];
    protected function casts(): array { return ['version'=>'integer','revoked_at'=>'immutable_datetime','last_opened_at'=>'immutable_datetime']; }
}
