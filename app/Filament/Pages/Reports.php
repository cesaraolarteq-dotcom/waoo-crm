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

    public ?array $emailPreview = null;

    public static function canAccess(): bool
    {
        return parent::canAccess() && ReportsSource::available();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->kind = in_array($this->kind, ['weekly', 'monthly'], true) ? $this->kind : 'weekly';
        $today = now('America/Chicago')->toDateString();
        $this->asOf = ($d = self::validDate($this->asOf)) && $d <= $today ? $d : $today;
    }

    private static function validDate(?string $d): ?string
    {
        if (! is_string($d) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        $c = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);

        return $c && $c->format('Y-m-d') === $d ? $d : null;
    }

    public function getSubheading(): ?string
    {
        return 'La última semana o el último mes ya cerrados. El dinero cuenta por la fecha en que llegó; los saldos y el costo del staff, por la fecha de cada fiesta.';
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

    public function analytics(): ?array
    {
        if (!config('waoo.sandbox_analytics', false)) return null;
        return app(\App\Services\Reports\ReadBookingAnalytics::class)->execute(
            Filament::auth()->user(), ['year' => (int)substr($this->asOf, 0, 4)]);
    }

    public function exportAnalytics(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $report = app(\App\Services\Reports\ReadBookingAnalytics::class)->execute(
            Filament::auth()->user(), ['year' => (int)substr($this->asOf, 0, 4)]);
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
