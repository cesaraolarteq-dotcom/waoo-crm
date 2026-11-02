<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PortalGrant extends Model
{
    protected $guarded = ['id', 'encrypted_token', 'quote_revision_id', 'cutover_financial_binding_id', 'opening_snapshot_sha256']; protected $hidden = ['token_hash', 'encrypted_token', 'cutover_financial_binding_id', 'opening_snapshot_sha256'];
    public function canRevealLink(): bool { return !$this->revoked_at && $this->expires_at->isFuture() && is_string($this->encrypted_token) && $this->encrypted_token !== ''; }
    protected function casts(): array { return ['scopes' => 'array', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime']; }
}
