<?php

namespace App\Services\Reports;

use App\Services\Records\RecordInput;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

final class ReportPeriod
{
    public static function resolve(array $input): array
    {
        $data = RecordInput::validate($input, [
            'period' => ['required', Rule::in(['week', 'month'])],
            'anchor' => 'required|date_format:Y-m-d|after_or_equal:2000-01-01|before:2101-01-01',
        ]);
        $anchor = CarbonImmutable::createFromFormat('!Y-m-d', $data['anchor'], 'America/Chicago');
        $start = $data['period'] === 'week' ? $anchor->startOfWeek(1) : $anchor->startOfMonth();
        $end = $data['period'] === 'week' ? $start->addWeek() : $start->addMonth();
        return ['kind' => $data['period'], 'starts_on' => $start->toDateString(), 'ends_before' => $end->toDateString(),
            'starts_at_utc' => $start->utc()->format('Y-m-d H:i:s'), 'ends_before_utc' => $end->utc()->format('Y-m-d H:i:s')];
    }
}
