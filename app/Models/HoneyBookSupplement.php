<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class HoneyBookSupplement extends Model
{
    protected $table = 'honeybook_supplements';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['payload', 'fingerprint'];
    protected function casts(): array { return ['payload' => 'encrypted:array']; }
    public static function fingerprintFor(array $row): string {
        $canonical = array_map(fn ($key) => $row[$key], ['source_type','source_id','category','field','value']);
        return hash_hmac('sha256', json_encode(['honeybook-supplement-v1', $canonical], JSON_THROW_ON_ERROR), config('app.key'));
    }
    public function evidence(): array {
        $payload = $this->payload;
        if (($payload['profile'] ?? null) !== 'honeybook-supplement-v1' ||
            ! hash_equals($this->fingerprint, self::fingerprintFor($payload['record']))) {
            abort(409, 'La evidencia debe revisarse de nuevo.');
        }
        return $payload;
    }
    protected static function booted(): void {
        static::updating(fn () => throw new \LogicException('Immutable source evidence.'));
        static::deleting(fn () => throw new \LogicException('Immutable source evidence.'));
    }
}
