<?php

namespace App\Filament\Support;

use App\Models\User;
use Carbon\CarbonImmutable;

// REPORT-A owner screen data. Calls Codex's report services (docs/REPORT-A-*.md) when they exist; until they are
// merged, local/testing can show a labelled sample with the exact same keys so the screen can be reviewed.
// Never invents real numbers: production without the services shows nothing.
final class ReportsSource
{
    public const OPERATIONS = 'App\\Services\\Reports\\OperationalReport';
    public const BOOKINGS = 'App\\Services\\Reports\\BookingSalesReport';
    public const WEEKEND = 'App\\Services\\Reports\\WeekendForecast';
    public const PREPARE = 'App\\Services\\Reports\\PrepareOwnerReport';

    public static function live(): bool
    {
        return class_exists(self::OPERATIONS) && class_exists(self::BOOKINGS) && class_exists(self::WEEKEND);
    }

    public static function sample(): bool
    {
        return ! self::live() && app()->environment(['local', 'testing']);
    }

    public static function available(): bool
    {
        return FinanceGate::enabled() && (self::live() || self::sample());
    }

    /** @return array{operations: array, bookings: array, weekend: array, sample: bool} */
    public static function read(User $owner, string $period, string $anchor): array
    {
        if (self::live()) {
            $in = ['period' => $period, 'anchor' => $anchor];

            return ['operations' => app(self::OPERATIONS)->execute($owner, $in), 'bookings' => app(self::BOOKINGS)->execute($owner, $in),
                'weekend' => app(self::WEEKEND)->execute($owner, ['anchor' => $anchor]), 'sample' => false];
        }

        return [...self::fixture($period, $anchor), 'sample' => true];
    }

    /** Fictitious payloads shaped exactly like the REPORT-A contracts (local/testing preview only). */
    public static function fixture(string $period, string $anchor): array
    {
        $a = CarbonImmutable::parse($anchor, 'America/Chicago');
        $start = $period === 'month' ? $a->startOfMonth() : $a->startOfWeek(CarbonImmutable::MONDAY);
        $end = $period === 'month' ? $start->addMonth() : $start->addWeek();
        $p = ['kind' => $period, 'starts_on' => $start->toDateString(), 'ends_before' => $end->toDateString(),
            'starts_at_utc' => $start->utc()->format('Y-m-d H:i:s'), 'ends_before_utc' => $end->utc()->format('Y-m-d H:i:s')];
        $agg = fn ($known, $pending, $direct, $staff) => ['known_direct_cost_cents' => $known, 'pending_events' => $pending, 'direct_cost_cents' => $direct, 'staff_cost_cents' => $staff];
        $weekendStart = $start->addDays(5);

        return [
            'operations' => ['environment' => 'test', 'timezone' => 'America/Chicago', 'generated_at' => now()->toIso8601String(), 'period' => $p,
                'cash' => ['basis' => 'ledger.received_at', 'received_cents' => 184500, 'refunded_cents' => 0, 'net_received_cents' => 184500],
                'scheduled' => ['basis' => 'events.starts_at', 'event_count' => 6, 'events' => [], 'pending_balance_cents' => 212000, 'pending_balance_unknown_events' => 1,
                    'estimate' => $agg(93000, 0, 93000, 81000), 'actual' => $agg(41000, 3, null, null)]],
            'bookings' => ['environment' => 'test', 'timezone' => 'America/Chicago', 'generated_at' => now()->toIso8601String(), 'period' => $p,
                'basis' => 'first_booking.capacity_reserved.created_at', 'booking_count' => 4, 'package_coverage_complete' => false, 'package_unknown_booking_count' => 1,
                'package_occurrence_count' => null, 'known_package_occurrence_count' => 4,
                'packages' => [['service_variant_id' => 1, 'name' => 'Magic Package', 'occurrence_count' => 1, 'booking_count' => 1],
                    ['service_variant_id' => 2, 'name' => 'Waoo Package', 'occurrence_count' => 2, 'booking_count' => 2],
                    ['service_variant_id' => 3, 'name' => 'Face Painting', 'occurrence_count' => 1, 'booking_count' => 1]]],
            'weekend' => ['environment' => 'test', 'timezone' => 'America/Chicago', 'generated_at' => now()->toIso8601String(),
                'window' => ['starts_on' => $weekendStart->toDateString(), 'ends_before' => $weekendStart->addDays(2)->toDateString()],
                'basis' => 'confirmed_events.starts_at', 'event_count' => 3, 'draft_event_count' => 1, 'events' => [],
                'pending_balance_cents' => 96500, 'pending_balance_unknown_events' => 0,
                'estimate' => $agg(45000, 0, 45000, 39000), 'actual' => $agg(0, 3, null, null)],
        ];
    }

    /** "2 Waoo Package · 1 Magic Package" (bookings that included each package). */
    public static function packagesLine(array $bookings): string
    {
        return collect($bookings['packages'] ?? [])->sortByDesc('booking_count')->map(fn ($p) => $p['booking_count'].' '.$p['name'])->implode(' · ');
    }

    public static function periodLabel(array $period): string
    {
        $s = CarbonImmutable::parse($period['starts_on'], 'America/Chicago')->locale('es');
        $e = CarbonImmutable::parse($period['ends_before'], 'America/Chicago')->subDay()->locale('es');

        return $period['kind'] === 'month' ? ucfirst($s->translatedFormat('F Y'))
            : 'Semana del '.$s->translatedFormat('D j M').' al '.$e->translatedFormat('D j M Y');
    }
}
