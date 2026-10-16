<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PortalGrant extends Model
{
    protected $guarded = ['id']; protected $hidden = ['token_hash'];
    protected function casts(): array { return ['scopes' => 'array', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime']; }
}
