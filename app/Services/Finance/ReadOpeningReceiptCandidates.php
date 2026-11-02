<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, LedgerEntry, OpeningReceiptAllocation, User};
use App\Services\Imports\{ImportInput, ImportPersistence};

final class ReadOpeningReceiptCandidates
{
    public function execute(User $actor, int $eventId, array $filters = []): array
    {
        $actor = OpeningReceiptAccess::actor($actor);
        $d = ImportInput::input($filters, ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        return ImportPersistence::transaction(function () use ($actor, $eventId, $d) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); OpeningReceiptAccess::actor($actor, true);
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail(); $c = EffectiveOpeningAgreement::find($event);
            RecordWriteRejected::unless($c !== null, 409, 'No hay un cambio efectivo autorizado para esta fiesta.');
            $entries = LedgerEntry::query()->forceIndex('opening_receipts_event_index')->where('event_id', $eventId)->orderBy('id')->limit(10001)->lockForUpdate()->get();
            RecordWriteRejected::unless($entries->count() <= 10000, 409, 'La cantidad de recibos requiere revisión por volumen.');
            $total = $entries->count(); $size = (int)($d['per_page'] ?? 25); $last = max(1, (int)ceil($total / $size));
            $page = min((int)($d['page'] ?? 1), $last); $rows = [];
            foreach ($entries->slice(($page - 1) * $size, $size) as $entry) {
                $proof = OpeningReceiptProof::inspect($entry, $c);
                $a = OpeningReceiptAllocation::where('ledger_entry_id', $entry->id)->first();
                if ($a) { try { OpeningReceiptLink::verify($a, $entry, $c, $proof); } catch (RecordWriteRejected) { $proof['eligible'] = false; $proof['issue_codes'][] = 'allocation_integrity_invalid'; } }
                $rows[] = ['ledger_entry_id' => $entry->id, 'amount_cents' => $entry->amount_cents, 'currency' => $entry->currency,
                    'principal_cents' => $proof['eligible'] ? $proof['principal_cents'] : null,
                    'customer_fee_cents' => $proof['eligible'] ? $proof['customer_fee_cents'] : null, 'kind' => $entry->kind, 'method' => $entry->method, 'received_at' => $entry->received_at?->toIso8601String(), 'allocated' => $a !== null,
                    'eligible' => $proof['eligible'] && $c['collection_current'], 'issue_codes' => $proof['issue_codes'], 'receipt_sha256' => $proof['receipt_sha256']];
            }
            return ['opening_id' => $c['binding']->cutover_opening_agreement_id, 'transfer_id' => $c['transfer']->id,
                'expected_collection_sha256' => $c['collection_sha256'], 'collection_current' => $c['collection_current'], 'missing' => $c['missing'],
                'page' => ['number' => $page, 'last' => $last, 'total' => $total], 'rows' => $rows];
        });
    }
}
