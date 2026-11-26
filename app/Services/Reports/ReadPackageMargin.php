<?php

namespace App\Services\Reports;

use App\Models\{Event, User};
use App\Services\Finance\Sandbox;
use App\Services\Portal\PortalContext;
use App\Services\Records\{RecordActor, RecordInput};
use Carbon\CarbonImmutable;

final class ReadPackageMargin
{
    public function execute(User $actor, array $input): array
    {
        Sandbox::require();
        RecordActor::authorize($actor);
        PortalContext::requireTransport(request());
        $data = RecordInput::validate($input, ['year' => 'required|integer|min:2027|max:2100']);
        $year = (int) $data['year'];
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, 'America/Chicago');
        $packages = [];
        $included = $pending = 0;

        $events = Event::query()->whereIn('status', ['capacity_reserved', 'completed'])
            ->where('starts_at', '>=', $start->utc())->where('starts_at', '<', $start->addYear()->utc())
            ->with(['latestMarginFinance', 'latestActualCost'])->lazyById();

        foreach ($events as $event) {
            $finance = $event->latestMarginFinance;
            $cost = $event->latestActualCost;
            $lines = $finance?->lines_snapshot ?? [];
            // A single agreement line can absorb all direct costs without a guessed split.
            if (count($lines) !== 1 || ($lines[0]['extends_event_snapshot'] ?? false)
                || !isset($lines[0]['service_variant_id'], $lines[0]['name'])
                || !$cost?->complete || $cost->financial_revision_id !== $finance->id
                || $cost->event_version !== $event->version
                || $finance->currency !== 'USD' || $cost->currency !== 'USD') {
                $pending++;
                continue;
            }

            $line = $lines[0];
            $id = $line['service_variant_id'];
            $revenue = (int) $finance->subtotal_cents - (int) $finance->discount_cents;
            $directCost = (int) collect($cost->lines_snapshot)->reject(fn ($row) => $row['category'] === 'card_processing')->sum('amount_cents');
            $packages[$id] ??= ['service_variant_id' => $id, 'name' => $line['name'], 'events' => 0,
                'revenue_cents' => 0, 'cost_cents' => 0, 'margin_cents' => 0];
            $packages[$id]['events']++;
            $packages[$id]['revenue_cents'] += $revenue;
            $packages[$id]['cost_cents'] += $directCost;
            $packages[$id]['margin_cents'] += $revenue - $directCost;
            $included++;
        }

        ksort($packages);
        return ['year' => $year, 'currency' => 'USD', 'included_events' => $included,
            'pending_events' => $pending, 'packages' => array_values($packages)];
    }
}
