<?php

namespace App\Filament\Pages;

use App\Filament\Support\{ChicagoTime, Labels};
use App\Models\{Event, ResourceReservation};
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

// Read-only calendar (Phase 1 step 9). Month / week / day in Houston time, package colors from the logo,
// setup + breakdown shown around each party, and the 45-minute minimum gap checked between parties that share a unit.
class Calendar extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;
    protected static string|\UnitEnum|null $navigationGroup = 'Día a día';
    protected static ?string $navigationLabel = 'Calendario';
    protected static ?int $navigationSort = 5;
    protected static ?string $slug = 'calendario';
    protected static ?string $title = 'Calendario';
    protected string $view = 'filament.pages.calendar';

    public const GAP_MINUTES = 45;

    #[Url]
    public string $mode = 'week';

    #[Url]
    public ?string $date = null;

    public function mount(): void
    {
        $this->mode = in_array($this->mode, ['month', 'week', 'day'], true) ? $this->mode : 'week';
        $this->date = $this->validDate($this->date) ?? CarbonImmutable::now(ChicagoTime::TZ)->format('Y-m-d');
    }

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, ['month', 'week', 'day'], true) ? $mode : 'week';
    }

    public function move(int $step): void
    {
        $d = $this->anchor();
        $next = match ($this->mode) {
            'month' => $d->startOfMonth()->addMonths($step),
            'week' => $d->addWeeks($step),
            default => $d->addDays($step),
        };
        $this->date = $next->format('Y-m-d');
    }

    public function today(): void
    {
        $this->date = CarbonImmutable::now(ChicagoTime::TZ)->format('Y-m-d');
    }

    public function openDay(string $day): void
    {
        $this->date = $this->validDate($day) ?? $this->date;
        $this->mode = 'day';
    }

    public function anchor(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->date, ChicagoTime::TZ)->startOfDay();
    }

    /** [first day, last day] shown, in Houston time. */
    public function range(): array
    {
        $d = $this->anchor();

        return match ($this->mode) {
            'month' => [$d->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY), $d->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
            'week' => [$d->startOfWeek(CarbonImmutable::MONDAY), $d->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
            default => [$d, $d],
        };
    }

    public function heading(): string
    {
        [$from, $to] = $this->range();
        $a = $this->anchor()->locale('es');

        return match ($this->mode) {
            'month' => ucfirst($a->translatedFormat('F Y')),
            'week' => $from->locale('es')->translatedFormat('j M').' – '.$to->locale('es')->translatedFormat('j M Y'),
            default => ucfirst($a->translatedFormat('l j \d\e F Y')),
        };
    }

    /** Events in range grouped by Houston date (Y-m-d), each decorated for display. */
    public function eventsByDay(): Collection
    {
        [$from, $to] = $this->range();
        $events = Event::query()->with(['customer', 'services', 'reservations'])
            ->where('starts_at', '<', $to->addDay()->utc())->where('ends_at', '>', $from->utc())
            ->orderBy('starts_at')->get();
        $names = \App\Models\ServiceVariant::query()->whereIn('id', $events->flatMap(fn ($e) => $e->services->pluck('service_variant_id')))->pluck('name', 'id');
        $units = \App\Models\Resource::query()->whereIn('id', $events->flatMap(fn ($e) => $e->reservations->pluck('resource_id')))->pluck('name', 'id');
        $gaps = $this->gapWarnings($events);

        return $events->map(function (Event $e) use ($names, $units, $gaps) {
            $start = $e->starts_at->setTimezone(ChicagoTime::TZ);
            $end = $e->ends_at->setTimezone(ChicagoTime::TZ);
            $services = $e->services->map(fn ($s) => $names[$s->service_variant_id] ?? '?');

            return (object) [
                'id' => $e->id, 'day' => $start->format('Y-m-d'), 'title' => $e->title, 'client' => $e->customer?->name,
                'start' => $start, 'end' => $end,
                'prep_from' => $e->setup_minutes ? $start->subMinutes($e->setup_minutes) : null,
                'prep_to' => $e->breakdown_minutes ? $end->addMinutes($e->breakdown_minutes) : null,
                'time' => $start->locale('es')->translatedFormat('g:i a').' – '.$end->locale('es')->translatedFormat('g:i a'),
                'services' => $services->implode(' + ') ?: 'Sin servicios',
                'pkg' => self::packageColor($services->first()),
                'units' => $e->reservations->map(fn ($r) => $units[$r->resource_id] ?? '?')->implode(', '),
                'status' => Labels::label(Labels::EVENT_STATUS, $e->status),
                'status_color' => Labels::EVENT_STATUS_COLOR[$e->status] ?? 'gray',
                'zip' => $e->postal_code,
                'travel' => Labels::money($e->travel_fee_cents),
                'warnings' => $gaps[$e->id] ?? [],
                'url' => \App\Filament\Resources\Events\EventResource::canEdit($e) ? \App\Filament\Resources\Events\EventResource::getUrl('edit', ['record' => $e]) : null,
            ];
        })->groupBy('day');
    }

    /** Parties sharing a unit with less than 45 min between one's breakdown and the next one's setup (before driving). */
    public function gapWarnings(Collection $events): array
    {
        $warnings = [];
        $byUnit = ResourceReservation::query()->whereIn('event_id', $events->pluck('id'))->get()->groupBy('resource_id');
        foreach ($byUnit as $unitId => $rows) {
            $list = $events->whereIn('id', $rows->pluck('event_id'))->sortBy('starts_at')->values();
            for ($i = 1; $i < $list->count(); $i++) {
                $prev = $list[$i - 1];
                $next = $list[$i];
                $free = $prev->ends_at->addMinutes((int) $prev->breakdown_minutes)->diffInMinutes($next->starts_at->subMinutes((int) $next->setup_minutes), false);
                if ($free < self::GAP_MINUTES) {
                    $name = \App\Models\Resource::whereKey($unitId)->value('name');
                    $msg = "{$name}: solo ".max(0, (int) $free).' min entre fiestas, antes de manejar (mínimo '.self::GAP_MINUTES.').';
                    $warnings[$prev->id][] = $msg;
                    $warnings[$next->id][] = $msg;
                }
            }
        }

        return $warnings;
    }

    /** Logo color per package (UX tokens): Star yellow, Magic purple, Music & Games teal, 2.0 pink, Waoo navy. */
    public static function packageColor(?string $name): string
    {
        $n = mb_strtolower((string) $name);

        return match (true) {
            str_contains($n, '2.0') => '#FF017C',
            str_contains($n, 'waoo') => '#122F94',
            str_contains($n, 'música') || str_contains($n, 'musica') || str_contains($n, 'music') => '#15D1C4',
            str_contains($n, 'mágico') || str_contains($n, 'magico') || str_contains($n, 'magic') => '#8D53D9',
            str_contains($n, 'estrella') || str_contains($n, 'star') => '#F9C930',
            default => '#8A91B0',
        };
    }

    private function validDate(?string $d): ?string
    {
        return $d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && CarbonImmutable::createFromFormat('Y-m-d', $d) ? $d : null;
    }
}
