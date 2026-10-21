<?php

namespace App\Services\Reports;

use App\Models\{Event, EventCostSnapshot, FinancialRevision, LedgerEntry, User};
use App\Services\Finance\Sandbox;
use App\Services\Records\RecordActor;
use Illuminate\Support\Facades\DB;

final class OperationalReport
{
    public function execute(User $actor, array $input): array
    {
        Sandbox::require();
        \App\Services\Portal\PortalContext::requireTransport(request());
        RecordActor::authorize($actor);
        $period = ReportPeriod::resolve($input);
        $from = $period['starts_at_utc'];
        $until = $period['ends_before_utc'];

        return DB::transaction(function () use ($period, $from, $until) {
            // Parent-row locks are shared with financial/cost/receipt writers. Fixed order avoids
            // different report requests taking these locks in opposite orders. No ledger writes.
            $events = Event::where('starts_at', '>=', $from)->where('starts_at', '<', $until)
                ->orderBy('id')->lockForUpdate()->get();
            $ids = $events->modelKeys();
            $finances = FinancialRevision::whereIn('event_id', $ids)->orderByDesc('revision')->get()->unique('event_id')->keyBy('event_id');
            $costs = EventCostSnapshot::whereIn('event_id', $ids)->orderByDesc('revision')->get()->unique(fn ($c) => $c->event_id.':'.$c->kind)->keyBy(fn ($c) => $c->event_id.':'.$c->kind);
            $ledger = LedgerEntry::whereIn('event_id', $ids)->where('environment', 'test')->where('currency', 'USD')
                ->selectRaw("event_id, SUM(CASE WHEN kind = 'receipt' THEN amount_cents WHEN kind = 'refund' THEN -amount_cents ELSE 0 END) AS net")
                ->groupBy('event_id')->get()->keyBy('event_id');
            $cashRows = LedgerEntry::where('environment', 'test')->where('currency', 'USD')->where('received_at', '>=', $from)->where('received_at', '<', $until)
                ->selectRaw('kind, SUM(amount_cents) AS amount')->groupBy('kind')->get()->keyBy('kind');
            $received = (int) ($cashRows->get('receipt')?->amount ?? 0);
            $refunded = (int) ($cashRows->get('refund')?->amount ?? 0);
            $scheduled = ['basis' => 'events.starts_at', 'event_count' => $events->count(), 'events' => [], 'pending_balance_cents' => 0, 'pending_balance_unknown_events' => 0];
            foreach (['estimate', 'actual'] as $kind) {
                $scheduled[$kind] = ['known_direct_cost_cents' => 0, 'pending_events' => 0, 'direct_cost_cents' => 0, 'staff_cost_cents' => 0];
            }
            foreach ($events as $event) {
                $finance = $finances->get($event->id);
                $current = $finance && $finance->currency === 'USD' && ($event->status !== 'draft' || $finance->event_version === $event->version);
                $balance = $current ? max($finance->total_cents - (int) ($ledger->get($event->id)?->net ?? 0), 0) : null;
                if ($balance === null) $scheduled['pending_balance_unknown_events']++;
                else $scheduled['pending_balance_cents'] += $balance;
                $row = ['event_id' => $event->id, 'status' => $event->status, 'starts_at' => $event->starts_at->toIso8601String(),
                    'financial_revision_id' => $finance?->id, 'balance_cents' => $balance];
                foreach (['estimate', 'actual'] as $kind) {
                    $snapshot = $costs->get($event->id.':'.$kind);
                    $fresh = $current && $snapshot && $snapshot->currency === 'USD' && $snapshot->financial_revision_id === $finance->id && $snapshot->event_version === $event->version;
                    $ready = $fresh && $snapshot->complete;
                    $staff = $ready ? array_sum(array_map(fn ($line) => in_array($line['category'], ['staff_labor', 'staff_transport'], true) ? $line['amount_cents'] : 0, $snapshot->lines_snapshot)) : null;
                    $row[$kind] = ['revision' => $snapshot?->revision, 'current' => (bool) $fresh, 'complete' => (bool) ($snapshot?->complete ?? false),
                        'known_direct_cost_cents' => $snapshot?->known_cost_cents ?? 0, 'direct_cost_cents' => $ready ? $snapshot->known_cost_cents : null, 'staff_cost_cents' => $staff];
                    $scheduled[$kind]['known_direct_cost_cents'] += $row[$kind]['known_direct_cost_cents'];
                    if (! $ready) $scheduled[$kind]['pending_events']++;
                    else {
                        $scheduled[$kind]['direct_cost_cents'] += $snapshot->known_cost_cents;
                        $scheduled[$kind]['staff_cost_cents'] += $staff;
                    }
                }
                $scheduled['events'][] = $row;
            }
            foreach (['estimate', 'actual'] as $kind) {
                if ($scheduled[$kind]['pending_events'] > 0) {
                    $scheduled[$kind]['direct_cost_cents'] = null;
                    $scheduled[$kind]['staff_cost_cents'] = null;
                }
            }
            return ['environment' => 'test', 'timezone' => 'America/Chicago', 'generated_at' => now()->toIso8601String(),
                'period' => $period,
                'cash' => ['basis' => 'ledger.received_at', 'customer_fee_received_cents'=>(int)($cashRows->get('customer_fee')?->amount??0),'gross_received_cents'=>$received+(int)($cashRows->get('customer_fee')?->amount??0),'received_cents' => $received, 'refunded_cents' => $refunded, 'net_received_cents' => $received - $refunded], 'scheduled' => $scheduled];
        }, 3);
    }
}
