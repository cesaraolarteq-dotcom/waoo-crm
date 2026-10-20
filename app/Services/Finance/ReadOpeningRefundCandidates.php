<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, LedgerEntry, User};
use App\Services\Imports\{ImportInput, ImportPersistence};

final class ReadOpeningRefundCandidates
{
    public function execute(User $actor, int $eventId, array $filters = []): array
    {
        $actor = OpeningReceiptAccess::actor($actor);
        $d = ImportInput::input($filters, ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        return ImportPersistence::transaction(function () use ($actor, $eventId, $d) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); OpeningReceiptAccess::actor($actor, true);
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail(); $c = EffectiveOpeningAgreement::find($event);
            RecordWriteRejected::unless($c !== null, 409, 'No hay un cambio efectivo autorizado para esta fiesta.');
            $query = LedgerEntry::query()->forceIndex('opening_receipts_event_index')->where('event_id', $eventId)->where('kind', 'refund');
            // The event lock serializes capture; do not load all private rows merely to page them.
            $total = (clone $query)->count();
            RecordWriteRejected::unless($total <= 10000, 409, 'La cantidad de devoluciones requiere revisión por volumen.');
            $size = (int)($d['per_page'] ?? 25); $last = max(1, (int)ceil($total / $size)); $page = min((int)($d['page'] ?? 1), $last);
            $entries = $query->orderBy('id')->offset(($page - 1) * $size)->limit($size)->sharedLock()->get(); $rows = []; $parents = [];
            foreach ($entries as $entry) {
                $proof = OpeningRefundProof::inspect($entry, $c, $parents);
                $rows[] = ['ledger_entry_id' => $entry->id, 'parent_ledger_entry_id' => $proof['parent_ledger_entry_id'],
                    'gross_refund_cents' => $proof['valid'] ? $entry->amount_cents : null,
                    'received_at' => $proof['valid'] ? $entry->received_at?->toIso8601String() : null,
                    'allocated' => false, 'eligible' => false, 'issue_codes' => array_values(array_unique([...$proof['issue_codes'],
                        ...(!$c['collection_current'] ? ['refund_collection_not_current'] : []), 'refund_allocation_not_available'])),
                    'refund_sha256' => $proof['refund_sha256'], 'parent_remaining_principal_cents' => null,
                    'parent_remaining_customer_fee_cents' => null, 'parent_budget_sha256' => null];
            }
            return ['opening_id' => $c['binding']->cutover_opening_agreement_id, 'expected_collection_sha256' => $c['collection_sha256'],
                'collection_current' => $c['collection_current'], 'missing' => $c['missing'],
                'page' => ['number' => $page, 'last' => $last, 'total' => $total], 'rows' => $rows];
        });
    }
}
