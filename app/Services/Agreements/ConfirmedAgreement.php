<?php
namespace App\Services\Agreements;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, FinancialRevision, ImportedProjectLink};
use App\Services\Finance\{MutationKey, OpeningReceiptContext};
use App\Services\Responsibility\RequireCrmResponsibility;
use Illuminate\Support\Facades\DB;

/** Trusted current basis; each consumer still applies its own authorization and action rules. */
final class ConfirmedAgreement
{
    public static function lock(int $eventId): array
    {
        RecordWriteRejected::unless(DB::transactionLevel() > 0, 409, 'El acuerdo requiere una lectura transaccional.');
        $e = RequireCrmResponsibility::forEvent($eventId, true);
        if (ImportedProjectLink::where('event_id', $eventId)->sharedLock()->first()) {
            $opening = OpeningReceiptContext::current($e); $f = $opening['finance'];
            return ['event' => $e, 'finance' => $f, 'origin' => 'honeybook_opening', 'opening' => $opening,
                'snapshot_sha256' => MutationKey::hash(['domain' => 'honeybook_opening:v1', 'event_id' => $e->id,
                    'event_version' => $e->version, 'financial_revision_id' => $f->id, 'collection_sha256' => $opening['collection_sha256']])];
        }
        $f = FinancialRevision::where('event_id', $eventId)->orderByDesc('revision')->sharedLock()->first();
        $binding = DB::table('capacity_payment_links')->where('event_id', $eventId)->sharedLock()->first();
        $reserved = $e->reservations()->where('starts_at', $e->starts_at)->where('ends_at', $e->ends_at)->orderBy('id')->sharedLock()->first();
        RecordWriteRejected::unless($f && $e->status === 'capacity_reserved' && $e->version === $f->event_version + 1
            && $binding && (int)$binding->financial_revision_id === $f->id && $reserved,
            409, 'Revisa la confirmación y el acuerdo vigente de esta fiesta.');
        return ['event' => $e, 'finance' => $f, 'origin' => 'crm', 'opening' => null, 'snapshot_sha256' => self::nativeFingerprint($e, $f)];
    }
    public static function nativeFingerprint(Event $e, FinancialRevision $f): string
    {
        // Exact legacy TIP representation: existing native grants remain current.
        return MutationKey::hash(['event_id' => $e->id, 'event_version' => $e->version, 'customer_id' => $e->customer_id,
            'start' => $e->starts_at->toIso8601String(), 'end' => $e->ends_at->toIso8601String(), 'finance' => \App\Services\Integrity\EvidenceV1::financialRaw($f)]);
    }
}
