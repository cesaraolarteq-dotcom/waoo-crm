<?php
namespace App\Services\Finance;

use App\Models\{Event, FinancialRevision, LedgerEntry};

final class FinanceSummary
{
    public function forEvent(Event $event): array
    {
        if($imported=\App\Services\Imports\ImportedAgreement::summary($event->id))return [...$imported,'responsible_system'=>$event->responsible_system];
        $revision = FinancialRevision::where('event_id', $event->id)->orderByDesc('revision')->first();
        $received = (int) LedgerEntry::where('event_id', $event->id)->where('environment', 'test')->where('kind', 'receipt')->sum('amount_cents');
        $refunded = (int) LedgerEntry::where('event_id', $event->id)->where('environment', 'test')->where('kind', 'refund')->sum('amount_cents');
        $fee = (int) LedgerEntry::where('event_id',$event->id)->where('environment','test')->where('kind','customer_fee')->sum('amount_cents');
        $net = $received - $refunded;
        $contractState = \Illuminate\Support\Facades\Schema::hasTable('contracts') ? (\App\Models\Contract::where('event_id', $event->id)->whereIn('state', ['issued','signed'])->orderByDesc('revision')->value('state') ?? 'not_issued') : 'not_issued';
        return ['responsible_system' => $event->responsible_system, 'environment' => 'test', 'financial_revision' => $revision, 'received_cents' => $received, 'refunded_cents' => $refunded,
            'customer_fee_received_cents'=>$fee,'gross_received_cents'=>$received+$fee,'net_received_cents' => $net, 'balance_cents' => $revision ? max($revision->total_cents - $net, 0) : null,
            'credit_cents' => $revision ? max($net - $revision->total_cents, 0) : null,
            'deposit_shortfall_cents' => $revision ? max($revision->deposit_target_cents - $net, 0) : null,
            'balance_label' => $revision && 2 * ($revision->total_cents - $net) === $revision->total_cents ? 'Remaining 50% balance due' : 'Remaining balance due',
            'capacity_status' => $event->status, 'contract_status' => $contractState, 'exceptions' => \Illuminate\Support\Facades\DB::table('payment_exceptions')->where('event_id', $event->id)->where('state', 'open')->get(['id','kind','state','created_at'])->toArray()];
    }
}
