<?php

namespace App\Services\Reports;

use App\Models\User;
use Carbon\CarbonImmutable;

final class WeekendForecast
{
    public function __construct(private OperationalReport $reports) {}

    public function execute(User $actor, array $input): array
    {
        // Delegate validation, authorization and consistent financial/cost reads to one path.
        $report = $this->reports->execute($actor, ['period' => 'week', 'anchor' => $input['anchor'] ?? null]);
        $start = CarbonImmutable::parse($report['period']['starts_on'], 'America/Chicago')->addDays(5);
        $end = $start->addDays(2);
        $rows = array_values(array_filter($report['scheduled']['events'], function ($event) use ($start, $end) {
            $at = CarbonImmutable::parse($event['starts_at']);
            return $at->greaterThanOrEqualTo($start) && $at->lessThan($end);
        }));
        $draftCount = count(array_filter($rows, fn ($event) => $event['status'] === 'draft'));
        $events = array_values(array_filter($rows, fn ($event) => $event['status'] === 'capacity_reserved'));
        usort($events, fn ($a, $b) => CarbonImmutable::parse($a['starts_at'])->getTimestamp() <=> CarbonImmutable::parse($b['starts_at'])->getTimestamp() ?: $a['event_id'] <=> $b['event_id']);
        $result = ['environment' => $report['environment'], 'timezone' => $report['timezone'], 'generated_at' => $report['generated_at'],
            'window' => ['starts_on' => $start->toDateString(), 'ends_before' => $end->toDateString(), 'starts_at_utc' => $start->utc()->format('Y-m-d H:i:s'), 'ends_before_utc' => $end->utc()->format('Y-m-d H:i:s')],
            'basis' => 'confirmed_events.starts_at', 'event_count' => count($events), 'draft_event_count' => $draftCount, 'events' => $events,
            'pending_balance_cents' => 0, 'pending_balance_unknown_events' => 0];
        foreach ($events as $event) {
            if ($event['balance_cents'] === null) $result['pending_balance_unknown_events']++;
            else $result['pending_balance_cents'] += $event['balance_cents'];
        }
        foreach (['estimate', 'actual'] as $kind) {
            $pending = count(array_filter($events, fn ($event) => $event[$kind]['direct_cost_cents'] === null));
            $result[$kind] = ['known_direct_cost_cents' => array_sum(array_column(array_column($events, $kind), 'known_direct_cost_cents')),
                'pending_events' => $pending,
                'direct_cost_cents' => $pending ? null : array_sum(array_column(array_column($events, $kind), 'direct_cost_cents')),
                'staff_cost_cents' => $pending ? null : array_sum(array_column(array_column($events, $kind), 'staff_cost_cents'))];
        }
        return $result;
    }
}
