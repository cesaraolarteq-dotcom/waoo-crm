<?php
namespace App\Services\Agreements;

use App\Models\User;
use App\Services\Finance\Sandbox;
use App\Services\Imports\ImportPersistence;
use App\Services\Portal\PortalContext;
use App\Services\Records\RecordActor;

final class ReadConfirmedAgreement
{
    public function execute(User $actor, int $eventId): array
    {
        Sandbox::require(); PortalContext::requireTransport(request()); $actor = RecordActor::authorize($actor);
        return ImportPersistence::transaction(function () use ($actor, $eventId) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); RecordActor::authorize($actor, true);
            $a = ConfirmedAgreement::lock($eventId); $e = $a['event']; $f = $a['finance']; $o = $a['opening']['opening'] ?? null;
            return ['schema_version' => 1, 'event_id' => $e->id, 'event_version' => $e->version, 'origin' => $a['origin'],
                'financial_revision_id' => $f->id, 'snapshot_sha256' => $a['snapshot_sha256'], 'currency' => $f->currency,
                'total_cents' => $f->total_cents, 'deposit_target_cents' => $f->deposit_target_cents, 'balance_due_at' => $f->balance_due_at?->toIso8601String(),
                'source_balance_due_date' => $o['source_balance_due_date'] ?? null, 'source_due_precision' => $o['source_due_precision'] ?? null,
                'source_commercial_difference_cents' => $o ? $f->total_cents - ($f->subtotal_cents + $f->travel_fee_cents - $f->discount_cents) : null,
                'original_document' => $o ? ['sha256' => $o['document_sha256'], 'source_contract_signed' => $o['source_contract_signed'],
                    'owner_attests_signed_original' => $o['owner_attests_signed_original']] : null];
        });
    }
}
