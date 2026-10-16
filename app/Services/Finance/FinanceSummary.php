<?php
namespace App\Services\Finance;

use App\Models\{Event, FinancialRevision, LedgerEntry};

final class FinanceSummary
{
    public function forEvent(Event $event): array
    {
        $revision = FinancialRevision::where('event_id', $event->id)->orderByDesc('revision')->first();
        $received = (int) LedgerEntry::where('event_id', $event->id)->where('environment', 'test')->where('kind', 'receipt')->sum('amount_cents');
        $refunded = (int) LedgerEntry::where('event_id', $event->id)->where('environment', 'test')->where('kind', 'refund')->sum('amount_cents');
        $net = $received - $refunded;
        return ['environment' => 'test', 'financial_revision' => $revision, 'received_cents' => $received, 'refunded_cents' => $refunded,
            'net_received_cents' => $net, 'balance_cents' => $revision ? max($revision->total_cents - $net, 0) : null,
            'credit_cents' => $revision ? max($net - $revision->total_cents, 0) : null,
            'deposit_shortfall_cents' => $revision ? max($revision->deposit_target_cents - $net, 0) : null,
            'balance_label' => $revision && 2 * ($revision->total_cents - $net) === $revision->total_cents ? 'Remaining 50% balance due' : 'Remaining balance due',
            'capacity_status' => $event->status, 'contract_status' => 'not_issued', 'exceptions' => []];
    }
}
