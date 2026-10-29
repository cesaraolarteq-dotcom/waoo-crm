<?php

namespace App\Filament\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Support\{Labels, ReportsSource};
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// REPORT-A owner screen over Codex's consolidated panel (docs/REPORT-A-PANEL-API.md): the last COMPLETED week or month
// (never the ongoing one), plus the weekend after that week. Money in is by payment date; balances and staff cost by
// party date (different groups, never subtracted); unknown → "Pendiente", never $0. "Preparar" freezes the report and
// shows Codex's email preview; nothing is ever sent from here (delivery stays not_sent / disabled).
class Reports extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;
    protected static string|\UnitEnum|null $navigationGroup = 'Día a día';
    protected static ?string $navigationLabel = 'Reportes';
    protected static ?int $navigationSort = 30;
    protected static ?string $slug = 'reportes';
    protected static ?string $title = 'Reportes';
    protected string $view = 'filament.pages.reports';

    #[Url(as: 'tipo')]
    public string $kind = 'weekly';

    /** Any date inside the period AFTER the one shown (the panel reports the completed period before it). */
    #[Url(as: 'fecha')]
    public string $asOf = '';

    #[Url(as: 'ano')]
    public int $analyticsYear = 0;

    public ?array $emailPreview = null;

    /** Panorama window (César 2026-10-28): year, month, week or a custom from–to range, around `dia`. */
    #[Url(as: 'vista')]
    public string $window = 'year';

    #[Url(as: 'dia')]
    public string $anchor = '';

    #[Url(as: 'desde')]
    public string $rangeFrom = '';

    #[Url(as: 'hasta')]
    public string $rangeTo = '';

    #[Url(as: 'pestana')]
    public string $tab = 'pulso';

    /** The bar the owner tapped in "Ventas" (bucket start date); its parties are listed under the chart. */
    public string $focus = '';

    /** Pulse: deposit (booking), balance (second half) or all payments. */
    public string $pulseMode = 'deposit';

    private ?array $panoramaCache = null;

    public static function canAccess(): bool
    {
        return parent::canAccess() && ReportsSource::available();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->kind = in_array($this->kind, ['weekly', 'monthly'], true) ? $this->kind : 'weekly';
        if ($this->analyticsYear < 2000 || $this->analyticsYear > 2100) $this->analyticsYear = (int)now('America/Chicago')->year;
        $today = now('America/Chicago')->toDateString();
        $this->asOf = ($d = self::validDate($this->asOf)) && $d <= $today ? $d : $today;
        $this->window = in_array($this->window, ['year', 'month', 'week', 'custom'], true) ? $this->window : 'year';
        $this->anchor = self::validDate($this->anchor) ?? $today;
        $this->tab = in_array($this->tab, ['ventas', 'pulso', 'canales', 'zonas', 'viene'], true) ? $this->tab : 'pulso';
        $this->rangeFrom = self::validDate($this->rangeFrom) ?? CarbonImmutable::parse($today)->subMonths(3)->toDateString();
        $this->rangeTo = self::validDate($this->rangeTo) ?? $today;
    }

    public function setWindow(string $window): void
    {
        $this->window = in_array($window, ['year', 'month', 'week', 'custom'], true) ? $window : 'year';
        $this->panoramaCache = null;
        $this->focus = '';
    }

    public function setFocus(string $start): void
    {
        $this->focus = $this->focus === $start ? '' : (self::validDate($start) ?? '');
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['ventas', 'pulso', 'canales', 'zonas', 'viene'], true) ? $tab : 'pulso';
    }

    public function setPulseMode(string $mode): void
    {
        $this->pulseMode = in_array($mode, ['deposit', 'balance', 'all'], true) ? $mode : 'deposit';
    }

    public function moveWindow(int $step): void
    {
        $a = CarbonImmutable::parse($this->anchor, 'America/Chicago');
        $step = $step < 0 ? -1 : 1;
        $this->focus = '';
        $this->anchor = (match ($this->window) { 'month' => $a->addMonthsNoOverflow($step), 'week' => $a->addWeeks($step), default => $a->addYears($step) })->toDateString();
        $this->panoramaCache = null;
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['rangeFrom', 'rangeTo'], true)) {
            $this->{$name} = self::validDate($this->{$name}) ?? now('America/Chicago')->toDateString();
            $this->panoramaCache = null;
        }
    }

    public function panorama(): array
    {
        [$from, $to] = \App\Services\Reports\Panorama::window($this->window, $this->anchor, $this->rangeFrom, $this->rangeTo);

        return $this->panoramaCache ??= app(\App\Services\Reports\ReadPanorama::class)->execute(Filament::auth()->user(), $from, $to);
    }

    public function windowLabel(array $p): string
    {
        $f = CarbonImmutable::parse($p['from'], 'America/Chicago')->locale('es');
        $t = CarbonImmutable::parse($p['to'], 'America/Chicago')->locale('es');

        return match ($this->window) {
            'year' => (string) $f->year,
            'month' => ucfirst($f->translatedFormat('F Y')),
            default => $f->translatedFormat('j M Y').' – '.$t->translatedFormat('j M Y'),
        };
    }

    private static function validDate(?string $d): ?string
    {
        if (! is_string($d) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        $c = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);

        return $c && $c->format('Y-m-d') === $d ? $d : null;
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function setKind(string $kind): void
    {
        $this->kind = $kind === 'monthly' ? 'monthly' : 'weekly';
        $this->emailPreview = null;
    }

    public function shift(int $step): void
    {
        $a = CarbonImmutable::parse($this->asOf, 'America/Chicago');
        $next = ($this->kind === 'monthly' ? $a->addMonthsNoOverflow($step) : $a->addWeeks($step))->toDateString();
        $this->asOf = min($next, now('America/Chicago')->toDateString());
        $this->emailPreview = null;
    }

    public function latest(): void
    {
        $this->asOf = now('America/Chicago')->toDateString();
        $this->emailPreview = null;
    }

    /** The shown period is the latest completed one: there is no "next" beyond today. */
    public function atLatest(): bool
    {
        $today = now('America/Chicago');
        $a = CarbonImmutable::parse($this->asOf, 'America/Chicago');

        return $this->kind === 'monthly' ? $a->isSameMonth($today) : $a->startOfWeek(CarbonImmutable::MONDAY)->equalTo($today->startOfWeek(CarbonImmutable::MONDAY)->startOfDay());
    }

    public function report(): array
    {
        return ReportsSource::read(Filament::auth()->user(), $this->kind, $this->asOf);
    }

    public function birthdayDraft(): ?array
    {
        if (!config('waoo.sandbox_anniversary', false)) return null;
        return app(\App\Services\Campaigns\ReadBirthdayCampaignDraft::class)->execute(
            Filament::auth()->user(), ['as_of' => now('America/Chicago')->toDateString()]);
    }

    /** Owner 2026-10-29: the birthday list as names and next birthday dates, soonest first (still a draft: nothing is sent). */
    public function birthdays(): ?array
    {
        if (! ($draft = $this->birthdayDraft())) return null;
        $today = CarbonImmutable::now('America/Chicago')->startOfDay();
        $names = \App\Models\Customer::query()->whereIn('id', array_column($draft['items'], 'customer_id'))->pluck('name', 'id');
        $items = [];
        foreach ($draft['items'] as $i) {
            $d = CarbonImmutable::parse($i['source_date'], 'America/Chicago');
            while ($d < $today) $d = $d->addYearNoOverflow();
            $items[] = ['name' => $names[$i['customer_id']] ?? 'Cliente sin nombre', 'next' => $d->toDateString(), 'days' => (int) $today->diffInDays($d),
                'rebooked' => in_array('already_rebooked', $i['reasons'] ?? [], true)];
        }
        usort($items, fn ($a, $b) => $a['next'] <=> $b['next']);

        return ['count' => $draft['candidate_count'], 'soon' => array_values(array_filter($items, fn ($i) => $i['days'] <= 60)), 'items' => $items];
    }

    public function analytics(): ?array
    {
        if (!config('waoo.sandbox_analytics', false)) return null;
        return app(\App\Services\Reports\ReadBookingAnalytics::class)->execute(
            Filament::auth()->user(), ['year' => $this->analyticsYear]);
    }

    public function exportAnalytics(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $report = app(\App\Services\Reports\ReadBookingAnalytics::class)->execute(
            Filament::auth()->user(), ['year' => $this->analyticsYear]);
        return response()->streamDownload(function () use ($report) {
            echo app(\App\Services\Reports\BookingAnalytics::class)->csv($report);
        }, 'reservas-'.$report['year'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function money(?int $cents): string
    {
        return Labels::money($cents, 'Pendiente');
    }

    /** Freeze this completed period (first preparation wins) and show the email preview. Never sends. */
    public function prepare(): void
    {
        if (! class_exists(ReportsSource::PREPARE) || ! class_exists(ReportsSource::EMAIL)) {
            Notification::make()->warning()->title('Todavía no disponible')->body('Preparar el reporte llega con la parte de Codex.')->send();

            return;
        }
        try {
            $snapshot = app(ReportsSource::PREPARE)->execute(Filament::auth()->user(), ['kind' => $this->kind, 'as_of' => $this->asOf]);
            $this->emailPreview = app(ReportsSource::EMAIL)->execute(Filament::auth()->user(), (int) $snapshot->id);
        } catch (ValidationException|RecordWriteRejected|AuthorizationException|HttpExceptionInterface $e) {
            Notification::make()->danger()->title('No se preparó')->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()->title('Reporte preparado, sin enviar')->body('Quedó guardado tal cual; los correos todavía no están activos.')->send();
    }
}
