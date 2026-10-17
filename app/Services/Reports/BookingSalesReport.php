<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\Finance\Sandbox;
use App\Services\Records\RecordActor;
use Illuminate\Support\Facades\DB;

final class BookingSalesReport
{
    public function execute(User $actor, array $input): array
    {
        Sandbox::require();
        RecordActor::authorize($actor);
        $period = ReportPeriod::resolve($input);
        // Filter AFTER finding the first confirmation: a later duplicate must never become a sale.
        $first = DB::table('audit_entries')->where('subject_type', 'events')->where('action', 'booking.capacity_reserved')
            ->selectRaw('subject_id AS event_id, MIN(created_at) AS confirmed_at')->groupBy('subject_id');
        $rows = DB::query()->fromSub($first, 'bookings')
            ->leftJoin('capacity_payment_links as links', 'links.event_id', '=', 'bookings.event_id')
            ->leftJoin('financial_revisions as finance', function ($join) {
                $join->on('finance.id', '=', 'links.financial_revision_id')->on('finance.event_id', '=', 'bookings.event_id');
            })
            ->where('bookings.confirmed_at', '>=', $period['starts_at_utc'])->where('bookings.confirmed_at', '<', $period['ends_before_utc'])
            ->orderBy('bookings.event_id')->get(['finance.currency', 'finance.lines_snapshot']);
        $unknown = 0;
        $known = 0;
        $packages = [];
        foreach ($rows as $row) {
            $lines = $row->lines_snapshot === null ? null : json_decode($row->lines_snapshot, true);
            if ($row->currency !== 'USD' || !is_array($lines) || !array_is_list($lines) || $lines === [] || collect($lines)->contains(fn ($line) =>
                !is_array($line) || !isset($line['service_variant_id'], $line['name']) || !is_numeric($line['service_variant_id']) || (int) $line['service_variant_id'] < 1 || !is_string($line['name']) || trim($line['name']) === '')) {
                $unknown++;
                continue;
            }
            $seen = [];
            foreach ($lines as $line) {
                $id = (int) $line['service_variant_id'];
                $key = json_encode([$id, $line['name']], JSON_THROW_ON_ERROR);
                $packages[$key] ??= ['service_variant_id' => $id, 'name' => $line['name'], 'occurrence_count' => 0, 'booking_count' => 0];
                $packages[$key]['occurrence_count']++;
                if (!isset($seen[$key])) $packages[$key]['booking_count']++;
                $seen[$key] = true;
                $known++;
            }
        }
        $packages = array_values($packages);
        usort($packages, fn ($a, $b) => strcmp($a['name'], $b['name']) ?: $a['service_variant_id'] <=> $b['service_variant_id']);
        return ['environment' => 'test', 'timezone' => 'America/Chicago', 'generated_at' => now()->toIso8601String(), 'period' => $period,
            'basis' => 'first_booking.capacity_reserved.created_at', 'booking_count' => $rows->count(), 'package_coverage_complete' => $unknown === 0,
            'package_unknown_booking_count' => $unknown, 'package_occurrence_count' => $unknown ? null : $known,
            'known_package_occurrence_count' => $known, 'packages' => $packages];
    }
}
