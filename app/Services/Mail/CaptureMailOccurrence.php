<?php
namespace App\Services\Mail;

use App\Models\{MailOccurrence, MailOccurrenceState, User};
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;

final class CaptureMailOccurrence
{
    public const KEYS = ['inquiry_received', 'quote_sent', 'details_request', 'payment_received'];
    private const LOCATORS = ['event_id', 'customer_id', 'lead_id', 'submission_id', 'quote_id', 'quote_revision_id',
        'presentation_id', 'portal_grant_id', 'quick_booking_id', 'contract_id', 'financial_revision_id', 'ledger_entry_id', 'notice_id'];

    public static function enabled(): bool
    {
        return app()->environment(['local', 'testing', 'staging']) && config('waoo.sandbox_mail') === true;
    }
    public function record(string $key, int|string $sourceId, array $locator, ?User $actor = null): ?MailOccurrence
    {
        if (!self::enabled() || in_array($key, ApprovedMail::RETIRED, true)) return null;
        if (DB::transactionLevel() < 1) throw new \LogicException('Mail occurrences must share the business transaction.');
        if (!in_array($key, self::KEYS, true) || (string) $sourceId === '' || strlen((string) $sourceId) > 128
            || array_diff(array_keys($locator), self::LOCATORS)) throw new \InvalidArgumentException('Invalid mail occurrence locator.');
        if ($key === 'contract_ready' ? !is_string($sourceId) || !\Illuminate\Support\Str::isUuid($sourceId) : !is_int($sourceId) || $sourceId < 1) {
            throw new \InvalidArgumentException('Mail subjects contain identifiers only.');
        }
        foreach ($locator as $name => $id) {
            if ($name === 'contract_id' ? !is_string($id) || !\Illuminate\Support\Str::isUuid($id) : !is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('Mail locators contain identifiers only.');
            }
        }
        if (isset($locator['event_id'])) {
            // Inbound verified receipts must commit even if outbound automation is disallowed.
            $event = \App\Models\Event::whereKey($locator['event_id'])->lockForUpdate()->firstOrFail();
            if ($event->responsible_system !== 'crm') return null;
        }
        $identity = MutationKey::hash(['key' => $key, 'source_id' => (string) $sourceId]);
        // A later replacement link never retargets or silently resends a captured occurrence.
        if ($existing = MailOccurrence::where('dedupe_sha256', $identity)->first()) return $existing;
        try {
            // Savepoint keeps the domain transaction usable after a losing unique insert.
            $occurrence = DB::transaction(fn () => MailOccurrence::create([
                'dedupe_sha256' => $identity, 'key' => $key, 'source_id' => (string) $sourceId,
                'event_id' => $locator['event_id'] ?? null, 'customer_id' => $locator['customer_id'] ?? null,
                'lead_id' => $locator['lead_id'] ?? null, 'actor_id' => $actor?->id,
                'locator' => $locator, 'occurred_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // A current locking read sees the committed winner even under REPEATABLE READ.
            return MailOccurrence::where('dedupe_sha256', $identity)->lockForUpdate()->firstOrFail();
        }
        MailOccurrenceState::create(['mail_occurrence_id' => $occurrence->id, 'status' => 'pending']);
        return $occurrence;
    }
}
