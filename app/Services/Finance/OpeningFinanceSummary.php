<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{CutoverFinancialBinding, Event, ImportedProjectLink, ImportedProjectRevision, LedgerEntry, OpeningReceiptAllocation};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class OpeningFinanceSummary
{
    public static function forEvent(Event $event): ?array
    {
        $read = fn () => self::snapshot(Event::whereKey($event->id)->sharedLock()->firstOrFail());
        return DB::transactionLevel() > 0 ? $read() : DB::transaction($read, 3);
    }
    private static function snapshot(Event $event): ?array
    {
        $hasSlot = DB::table('cutover_active_openings')->where('event_id', $event->id)->sharedLock()->first() !== null;
        if (!$hasSlot && !CutoverFinancialBinding::where('event_id', $event->id)->sharedLock()->first()
            && !(ImportedProjectLink::where('event_id', $event->id)->sharedLock()->first()
                && ($event->responsible_system === 'crm' || LedgerEntry::query()->forceIndex('opening_receipts_event_index')->where('event_id', $event->id)->sharedLock()->first()))) return null;
        $base = ['environment' => 'test', 'source' => 'honeybook_opening', 'responsible_system' => $event->responsible_system,
            'funds_verified' => false, 'financial_revision' => null, 'received_cents' => null, 'refunded_cents' => null, 'net_received_cents' => null,
            'balance_cents' => null, 'credit_cents' => null, 'deposit_shortfall_cents' => 0, 'balance_label' => 'Imported agreement balance',
            'capacity_status' => $event->status, 'contract_status' => 'original_imported_agreement', 'collection_blocked' => true, 'exceptions' => []];
        try {
            if (!$hasSlot) return [...$base, 'opening_missing' => ['effective_opening_required']];
            $c = EffectiveOpeningAgreement::find($event);
            if (!$c) return [...$base, 'opening_missing' => ['effective_opening_required']];
            $o = $c['opening']; $movements = []; $customerFees = 0; $missing = $c['missing'];
            $ledger = LedgerEntry::query()->forceIndex('opening_receipts_event_index')->where('event_id', $event->id)->orderBy('id')->limit(10001)->sharedLock()->get();
            if ($ledger->count() > 10000) return [...$base, 'opening_missing' => ['receipt_volume_exceeded']];
            $allocations = OpeningReceiptAllocation::query()->forceIndex('opening_receipts_binding_index')->where('cutover_financial_binding_id', $c['binding']->id)->orderBy('id')->limit(10001)->sharedLock()->get()->keyBy('ledger_entry_id');
            if ($allocations->count() > 10000) return [...$base, 'opening_missing' => ['receipt_volume_exceeded']];
            foreach ($ledger as $entry) {
                $a = $allocations->get($entry->id);
                if (!$a) { $missing[] = 'unallocated_receipt_or_refund'; continue; }
                try { $p = OpeningReceiptLink::verify($a, $entry, $c);
                    $movements[] = ['kind' => 'receipt', 'amount_cents' => $p['principal_cents'] ?? $entry->amount_cents];
                    $customerFees += $p['customer_fee_cents'] ?? 0; }
                catch (RecordWriteRejected) { $missing[] = 'allocation_integrity_invalid'; }
            }
            if ($allocations->count() !== count($movements)) $missing[] = 'allocation_integrity_invalid';
            $money = OpeningBalance::calculate(array_intersect_key($o, array_flip(['original_total_cents', 'source_gross_received_cents', 'source_refund_cents', 'source_net_received_cents'])), $movements);
            $complete = array_intersect($missing, ['unallocated_receipt_or_refund', 'allocation_integrity_invalid']) === [];
            $exceptions = DB::table('payment_exceptions')->where('event_id', $event->id)->where('state', 'open')->sharedLock()->get(['id', 'kind', 'state', 'created_at'])->toArray();
            if ($exceptions !== []) $missing[] = 'payment_exception_open';
            $original = ImportedProjectRevision::whereKey($o['source_revision_id'])->sharedLock()->firstOrFail()->source_snapshot;
            $sourceDifference = $o['original_total_cents'] - (\App\Services\Imports\HoneyBookFutureSource::cents($original['package_total'])
                + \App\Services\Imports\HoneyBookFutureSource::cents($original['travel_fee']) - \App\Services\Imports\HoneyBookFutureSource::cents($original['discount']));
            return [...$base, 'financial_revision' => $c['finance'], 'opening_id' => $c['binding']->cutover_opening_agreement_id,
                'transfer_id' => $c['transfer']->id, 'opening_collection_sha256' => $c['collection_sha256'], 'opening_collection_current' => $c['collection_current'],
                'source_gross_received_cents' => $o['source_gross_received_cents'], 'source_refund_cents' => $o['source_refund_cents'],
                'source_net_received_cents' => $o['source_net_received_cents'], 'crm_received_cents' => $money['crm_received_cents'], 'crm_refunded_cents' => 0, 'crm_customer_fee_cents' => $customerFees,
                'source_commercial_difference_cents' => $sourceDifference,
                'known_net_received_cents' => $money['net_received_cents'], 'received_cents' => $complete ? $o['source_gross_received_cents'] + $money['crm_received_cents'] : null,
                'refunded_cents' => $complete ? $o['source_refund_cents'] : null, 'net_received_cents' => $complete ? $money['net_received_cents'] : null,
                'balance_cents' => $complete ? $money['balance_cents'] : null, 'credit_cents' => $complete ? $money['credit_cents'] : null,
                'source_balance_due_date' => $o['source_balance_due_date'], 'crm_balance_due_at' => $o['crm_balance_due_at'],
                'collection_blocked' => $missing !== [], 'opening_missing' => array_values(array_unique($missing)),
                'exceptions' => $exceptions];
        } catch (RecordWriteRejected|AuthorizationException) { return [...$base, 'opening_missing' => ['effective_opening_integrity_invalid']]; }
    }
}
