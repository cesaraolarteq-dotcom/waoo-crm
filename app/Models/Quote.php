<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['version' => 'integer', 'current_revision' => 'integer', 'valid_until' => 'immutable_datetime']; }
    public function revisions() { return $this->hasMany(QuoteRevision::class); }
    public function effectiveStatus(): string
    {
        return in_array($this->status, ['draft', 'sent'], true) && !$this->valid_until->isFuture() ? 'expired' : $this->status;
    }
}
