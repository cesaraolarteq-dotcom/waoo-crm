<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventChange extends Model
{
    protected $fillable = ['event_id', 'kind', 'state', 'old_starts_at', 'old_ends_at', 'new_starts_at', 'new_ends_at', 'event_version_before', 'event_version_after',
        'fee_decision', 'fee_reason', 'fee_cents', 'moved_reservations', 'financial_revision_id', 'contract_id', 'notified_at', 'reminded_at', 'created_by'];
    protected function casts(): array
    {
        return ['old_starts_at' => 'immutable_datetime', 'old_ends_at' => 'immutable_datetime', 'new_starts_at' => 'immutable_datetime', 'new_ends_at' => 'immutable_datetime',
            'notified_at' => 'datetime', 'reminded_at' => 'datetime', 'fee_cents' => 'integer', 'moved_reservations' => 'integer'];
    }

    /** The reschedule waiting for the client to sign a new contract (its signed contract predates the change). */
    public static function pendingSignature(int $eventId): ?self
    {
        return self::where('event_id', $eventId)->where('kind', 'reschedule')->where('state', 'pending_signature')->latest('id')->first();
    }

    /** A signed contract that a pending reschedule replaced: it stays on file but no longer blocks a new contract. */
    public static function signedIsBeforeChange(int $eventId): bool
    {
        $pending = self::pendingSignature($eventId);
        if (! $pending) return false;
        return ! Contract::where('event_id', $eventId)->where('state', 'signed')->where('event_version', '>=', $pending->event_version_after)->exists();
    }

    /** Only completed, signed date changes may bridge an older, unchanged client submission. */
    public static function preservesDetailsVersion(int $eventId, int $submissionVersion, int $beforeVersion): bool
    {
        for ($i = 0; $i < 100 && $beforeVersion > $submissionVersion; $i++) {
            $rows = self::where('event_id', $eventId)->where('kind', 'reschedule')->where('state', 'completed')
                ->where('event_version_after', $beforeVersion)->limit(2)->get();
            if ($rows->count() !== 1) return false;
            $change = $rows->first();
            if ($change->event_version_before >= $beforeVersion || ! Contract::whereKey($change->contract_id)
                ->where('event_id', $eventId)->where('state', 'signed')->where('event_version', $beforeVersion)
                ->where('financial_revision_id', $change->financial_revision_id)->exists()) return false;
            $beforeVersion = $change->event_version_before;
        }
        return $beforeVersion === $submissionVersion;
    }
}
