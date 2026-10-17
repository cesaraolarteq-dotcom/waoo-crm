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

// REPORT-A (owner request 2026-10-15): simple, visual week/month view over Codex's read-only report services.
// Money in is by payment date; balances and staff cost belong to the parties dated in the period — different
// groups, never subtracted into a fake "profit". Missing costs say "Pendiente", never $0. Nothing is sent from here.
class Reports extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;
    protected static string|\UnitEnum|null $navigationGroup = 'Día a día';
    protected static ?string $navigationLabel = 'Reportes';
    protected static ?int $navigationSort = 30;
    protected static ?string $slug = 'reportes';
    protected static ?string $title = 'Reportes';
    protected string $view = 'filament.pages.reports';

    #[Url(as: 'periodo')]
    public string $period = 'week';

    #[Url(as: 'fecha')]
    public string $anchor = '';

    public ?array $prepared = null;

    public static function canAccess(): bool
    {
        return parent::canAccess() && ReportsSource::available();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->period = in_array($this->period, ['week', 'month'], true) ? $this->period : 'week';
        $this->anchor = self::validDate($this->anchor) ?? now('America/Chicago')->toDateString();
    }

    private static function validDate(?string $d): ?string
    {
        if (! is_string($d) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        $c = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);

        return $c && $c->format('Y-m-d') === $d ? $d : null;
    }

    public function getSubheading(): ?string
    {
        return 'Lo esencial de la semana o del mes. El dinero cuenta por la fecha en que llegó; los saldos y el costo del staff, por la fecha de cada fiesta.';
    }

    public function setPeriod(string $period): void
    {
        $this->period = $period === 'month' ? 'month' : 'week';
    }

    public function move(int $step): void
    {
        $a = CarbonImmutable::parse($this->anchor, 'America/Chicago');
        $this->anchor = ($this->period === 'month' ? $a->addMonthsNoOverflow($step) : $a->addWeeks($step))->toDateString();
    }

    public function today(): void
    {
        $this->anchor = now('America/Chicago')->toDateString();
    }

    public function report(): array
    {
        return ReportsSource::read(Filament::auth()->user(), $this->period, $this->anchor);
    }

    public function money(?int $cents): string
    {
        return Labels::money($cents, 'Pendiente');
    }

    /** Freeze last completed week's/month's report (PrepareOwnerReport). It is NOT sent: MAIL activation is separate. */
    public function prepare(string $kind): void
    {
        if (! class_exists(ReportsSource::PREPARE)) {
            Notification::make()->warning()->title('Todavía no disponible')->body('Preparar el reporte llega con la parte de Codex.')->send();

            return;
        }
        try {
            $s = app(ReportsSource::PREPARE)->execute(Filament::auth()->user(), ['kind' => $kind === 'monthly' ? 'monthly' : 'weekly', 'as_of' => now('America/Chicago')->toDateString()]);
        } catch (ValidationException|RecordWriteRejected|AuthorizationException $e) {
            Notification::make()->danger()->title('No se preparó')->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())->persistent()->send();

            return;
        }
        $payload = json_decode($s->payload_json ?? json_encode($s->payload ?? []), true) ?: [];
        $this->prepared = ['kind' => $s->kind, 'starts' => $s->period_starts_on, 'ends' => $s->period_ends_before, 'recipient' => $s->recipient,
            'net' => $payload['operations']['cash']['net_received_cents'] ?? null, 'bookings' => $payload['bookings']['booking_count'] ?? null,
            'packages' => ReportsSource::packagesLine($payload['bookings'] ?? []), 'staff' => $payload['operations']['scheduled']['actual']['staff_cost_cents'] ?? null];
        Notification::make()->success()->title('Reporte preparado')->body('Quedó guardado tal cual. No se envió: los correos todavía no están activos.')->send();
    }
}
