<?php
namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{BookingEvidence, OperationalReview};
use App\Services\Finance\{MutationKey, OpeningBalance};

final class OpeningAgreementFacts
{
    /** Only call after a complete valid C3 A snapshot, inside the cohort locks. */
    public static function forMember(array $m): array
    {
        $view = $m['view']; $b = $m['binding']; $r = $m['r']; $n = $r->normalized;
        $review = CutoverIntegrity::review($m['review']);
        RecordWriteRejected::unless($view['missing'] === [] && $review !== null, 409, 'La evidencia del acuerdo requiere revisión.');
        $capacityRevision = $r->mapping['money_resync']['capacity_revision_id'] ?? $r->id;
        $evidence = BookingEvidence::where('event_id', $m['event']->id)->where('method', 'honeybook_import')
            ->where('imported_revision_id', $capacityRevision)->sharedLock()->firstOrFail();
        $operational = OperationalReview::where('event_id', $m['event']->id)->sharedLock()->firstOrFail();
        try {
            $money = OpeningBalance::calculate(['original_total_cents' => $view['source']['total_cents'],
                'source_gross_received_cents' => $view['source']['gross_received_cents'], 'source_refund_cents' => $view['source']['refund_cents'],
                'source_net_received_cents' => $view['source']['net_paid_cents']]);
        } catch (\InvalidArgumentException) { throw new RecordWriteRejected(409, 'Los importes exceden el alcance de esta preparación. Requieren revisión técnica.'); }
        return ['schema_version' => 1, 'origin' => 'honeybook_opening', 'authority' => 'preparation_only', 'effective_at' => null,
            'event_id' => $m['event']->id, 'event_version' => $m['event']->version,
            'customer_id' => $m['link']->customer_id, 'customer_version' => $view['source']['customer_version'],
            'imported_project_link_id' => $m['link']->id, 'link_version' => $m['link']->version, 'source_revision_id' => $r->id,
            'source_sha256' => $r->source_sha256, 'commercial_sha256' => $b['commercial_sha256'],
            'source_difference_sha256' => MutationKey::hash($r->mapping['source_difference'] ?? $r->mapping['money_resync']['source_difference'] ?? []),
            'operations_sha256' => $b['operations_sha256'], 'customer_sha256' => $b['customer_sha256'], 'binding_sha256' => $m['bindingHash'],
            'booking_evidence_id' => $evidence->id, 'capacity_revision_id' => $capacityRevision,
            'booking_evidence_sha256' => MutationKey::hash($evidence->toArray()), 'operational_review_id' => $operational->id,
            'operational_review_sha256' => MutationKey::hash($operational->toArray()), 'capacity_already_reserved' => true,
            'currency' => 'USD', ...$money, 'deposit_target_cents' => intdiv($money['original_total_cents'] + 1, 2),
            'source_balance_due_date' => $n['balance_due_date'] ?? null, 'source_due_precision' => empty($n['balance_due_date']) ? 'unknown' : 'date',
            'crm_balance_due_at' => $review['crm_balance_due_at'], 'funds_verified' => false,
            'document_id' => $m['document']->id, 'document_sha256' => $m['document']->sha256,
            'source_contract_signed' => $n['contract_signed'], 'owner_attests_signed_original' => true,
            'review_id' => $m['review']->id, 'review_version' => $m['review']->version, 'review_payload_sha256' => $m['review']->payload_sha256];
    }
    public static function cohort($links, $events, $grants): array
    {
        $facts = [];
        foreach ($links as $link) {
            $m = app(CutoverMember::class)->inspect($link, $events->get($link->event_id), $grants);
            $facts[(string)$link->event_id] = self::forMember($m);
        }
        ksort($facts); return $facts;
    }
    public static function hashes(array $facts): array
    {
        return array_map(fn ($f) => MutationKey::hash($f), $facts);
    }
}
