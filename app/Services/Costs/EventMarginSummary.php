<?php
namespace App\Services\Costs;

use App\Models\{Event, EventCostSnapshot, FinancialRevision, User};
use App\Services\Finance\{FinanceSummary, Sandbox};
use App\Services\Records\RecordActor;
use Illuminate\Support\Facades\DB;

final class EventMarginSummary
{
    public function forEvent(User $actor, int $eventId): array
    {
        Sandbox::require(); RecordActor::authorize($actor);
        return DB::transaction(function () use ($eventId) {
            // Cost/event/finance writes share this lock, so one report cannot mix revisions.
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            $finance = FinancialRevision::where('event_id', $eventId)->orderByDesc('revision')->first();
            $agreementCurrent = $finance && ($event->status !== 'draft' || $finance->event_version === $event->version);
            $revenue = $finance ? $finance->subtotal_cents + $finance->travel_fee_cents + $finance->customer_fee_cents : null;
            $money = app(FinanceSummary::class)->forEvent($event);
            $result = ['event_id' => $eventId, 'event_version' => $event->version, 'financial_revision_id' => $finance?->id, 'currency' => $finance?->currency,
                'agreement_current' => (bool) $agreementCurrent, 'revenue_cents' => $agreementCurrent ? $revenue : null,
                'cash_received_cents' => $money['net_received_cents'], 'customer_balance_cents' => $agreementCurrent ? $money['balance_cents'] : null];
            foreach (['estimate','actual'] as $kind) {
                $snapshot = EventCostSnapshot::where('event_id', $eventId)->where('kind', $kind)->orderByDesc('revision')->first();
                if (!$snapshot) { $result[$kind] = null; continue; }
                $current = $agreementCurrent && $snapshot->financial_revision_id === $finance->id && $snapshot->event_version === $event->version;
                $ready = $current && $snapshot->complete;
                $result[$kind] = [...$snapshot->only(['id','revision','note','lines_snapshot','known_cost_cents','unknown_line_count','coverage_confirmed','complete','created_at']),
                    'current' => (bool) $current, 'total_cost_cents' => $ready ? $snapshot->known_cost_cents : null,
                    'contribution_margin_cents' => $ready ? $revenue - $snapshot->known_cost_cents : null];
            }
            return $result;
        }, 3);
    }
}
