<?php

namespace App\Filament\Resources\Events\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Variants\Pages\EditServiceVariant;
use App\Filament\Support\{FinanceGate, Labels};
use App\Models\{EventCostSnapshot, FinancialRevision, User};
use App\Services\Costs\{EventMarginSummary, RecordCostSnapshot};
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\{Checkbox, Repeater, Select, Textarea, TextInput};
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

// "Costos y margen" of one event (COST-A first slice, sandbox, owner only). The owner types each direct cost;
// nothing is calculated from rates. Margin shows only when Codex's EventMarginSummary says it is complete and current:
// unknown costs or missing coverage show as pending, never as $0 profit.
class EventCosts extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EventResource::class;

    protected static ?string $title = 'Costos y margen';

    protected string $view = 'filament.pages.event-costs';

    public const CATEGORIES = ['staff_labor' => 'Pago al staff', 'staff_transport' => 'Transporte del staff', 'materials' => 'Materiales',
        'card_processing' => 'Comisión de tarjeta', 'other' => 'Otro'];

    public const KINDS = ['estimate' => 'Estimado', 'actual' => 'Real'];

    /** What the owner is looking at; a save sends these, so another editor's change is refused (409) instead of replaced. */
    #[Locked] public ?int $displayedEventVersion = null;
    #[Locked] public ?int $displayedFinanceId = null;
    #[Locked] public int $displayedEstimateRevision = 0;
    #[Locked] public int $displayedActualRevision = 0;
    #[Locked] public string $estimateKey = '';
    #[Locked] public string $actualKey = '';

    public function mount(int|string $record): void
    {
        abort_unless(FinanceGate::enabled() && class_exists(RecordCostSnapshot::class), 403);
        $this->record = $this->resolveRecord($record);
        $this->capture();
        $this->estimateKey = (string) Str::uuid();
        $this->actualKey = (string) Str::uuid();
    }

    private function capture(): void
    {
        $this->record->refresh();
        $this->displayedEventVersion = $this->record->version;
        $this->displayedFinanceId = FinancialRevision::where('event_id', $this->record->getKey())->orderByDesc('revision')->value('id');
        $this->displayedEstimateRevision = (int) EventCostSnapshot::where('event_id', $this->record->getKey())->where('kind', 'estimate')->max('revision');
        $this->displayedActualRevision = (int) EventCostSnapshot::where('event_id', $this->record->getKey())->where('kind', 'actual')->max('revision');
    }

    public function getSubheading(): ?string
    {
        return $this->record->title.' · '.Labels::when($this->record->starts_at).' · Solo tú ves esta pantalla. Modo prueba.';
    }

    public function summary(): array
    {
        return app(EventMarginSummary::class)->forEvent(Filament::auth()->user(), $this->record->getKey());
    }

    /** Services of the current agreement, to tie a cost to one of them (optional). */
    public function serviceOptions(): array
    {
        $finance = FinancialRevision::find($this->displayedFinanceId);

        return collect($finance?->lines_snapshot ?? [])->mapWithKeys(fn ($l) => [$l['event_service_id'] => $l['name'] ?? 'Servicio'])->all();
    }

    /** Why a margin is not shown yet, in plain words. */
    public static function pendingReasons(?array $s): array
    {
        if (! $s) {
            return ['Todavía no hay costos registrados.'];
        }
        $r = [];
        if (! $s['current']) $r[] = 'Los montos o el evento cambiaron después de estos costos: regístralos de nuevo.';
        if ($s['unknown_line_count'] > 0) $r[] = 'Faltan '.$s['unknown_line_count'].' '.($s['unknown_line_count'] === 1 ? 'costo' : 'costos').' por escribir.';
        if (! $s['coverage_confirmed']) $r[] = 'No has confirmado que están todos los costos.';

        return $r;
    }

    public function history()
    {
        return EventCostSnapshot::where('event_id', $this->record->getKey())->orderByDesc('id')->get()
            ->map(fn ($c) => (object) ['kind' => self::KINDS[$c->kind] ?? $c->kind, 'revision' => $c->revision, 'when' => Labels::when($c->created_at),
                'by' => User::whereKey($c->created_by)->value('name'), 'known' => Labels::money($c->known_cost_cents), 'complete' => $c->complete,
                'unknown' => $c->unknown_line_count, 'note' => $c->note]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->costAction('estimate'), $this->costAction('actual')];
    }

    public function estimateAction(): Action { return $this->costAction('estimate'); }

    public function actualAction(): Action { return $this->costAction('actual'); }

    private function costAction(string $kind): Action
    {
        $label = $kind === 'estimate' ? 'Registrar costos estimados' : 'Registrar costos reales';

        return Action::make($kind)->label($label)->icon($kind === 'estimate' ? 'heroicon-o-calculator' : 'heroicon-o-receipt-percent')
            ->color($kind === 'estimate' ? 'gray' : 'primary')
            ->visible(fn () => $this->displayedFinanceId !== null)
            ->modalHeading($label)
            ->modalDescription($kind === 'estimate'
                ? 'Lo que crees que te va a costar esta fiesta. Escribe cada costo directo; si todavía no sabes uno, déjalo vacío y el margen queda pendiente.'
                : 'Lo que de verdad pagaste por esta fiesta. Se guarda una versión nueva; las anteriores quedan en el historial.')
            ->fillForm(function () use ($kind) {
                // Start from the latest of this kind; the first "real" starts from the estimate. Owner 2026-10-28: the very first
                // estimate starts from the staff rates and $30 of materials per service; the owner reviews before saving.
                $from = EventCostSnapshot::where('event_id', $this->record->getKey())->where('kind', $kind)->orderByDesc('revision')->first()
                    ?? ($kind === 'actual' ? EventCostSnapshot::where('event_id', $this->record->getKey())->where('kind', 'estimate')->orderByDesc('revision')->first() : null);
                if (! $from && $kind === 'estimate') {
                    return ['lines' => \App\Support\StaffPayEstimate::lines($this->record), 'coverage_confirmed' => false,
                        'note' => 'Calculado con las tarifas del staff y $30 de materiales por servicio.'];
                }

                return ['lines' => collect($from?->lines_snapshot ?? [])->map(fn ($l) => ['label' => $l['label'], 'category' => $l['category'],
                    'amount' => $l['amount_cents'] === null ? null : rtrim(rtrim(number_format($l['amount_cents'] / 100, 2, '.', ''), '0'), '.'),
                    'event_service_id' => $l['event_service_id'] ?? null])->all(), 'coverage_confirmed' => false];
            })
            ->schema([
                Repeater::make('lines')->label('Costos')->defaultItems(0)->columns(4)->addActionLabel('Agregar costo')->maxItems(100)
                    ->schema([
                        TextInput::make('label')->label('Qué es')->required()->maxLength(120)->placeholder('Ej.: Animadora (Ana)'),
                        Select::make('category')->label('Tipo')->options(self::CATEGORIES)->required()->native(false),
                        TextInput::make('amount')->label('Monto (USD)')->prefix('$')->nullable()
                            ->rule('regex:/^\d{1,7}(\.\d{1,2})?$/')->validationMessages(['regex' => 'Solo el número, por ejemplo 60 o 12.50.'])
                            ->helperText('Vacío = todavía no lo sabes. 0 = no cuesta nada.'),
                        Select::make('event_service_id')->label('Servicio (opcional)')->options(fn () => $this->serviceOptions())->nullable()->native(false),
                    ]),
                Checkbox::make('coverage_confirmed')->label('Están todos los costos directos de esta fiesta (no falta ninguno)')
                    ->helperText('Sin esta casilla el margen queda pendiente.'),
                Textarea::make('note')->label('Nota')->required()->minLength(10)->maxLength(2000)->rows(2)
                    ->placeholder('Ej.: transporte compartido con otra fiesta; pagué 2 h a cada recreadora.'),
            ])
            ->modalSubmitActionLabel('Guardar costos')
            ->action(function (array $data, Action $action) use ($kind) {
                $lines = collect($data['lines'] ?? [])->map(fn ($l) => ['label' => trim((string) $l['label']), 'category' => $l['category'],
                    'amount_cents' => filled($l['amount'] ?? null) ? EditServiceVariant::toCents($l['amount']) : null,
                    'event_service_id' => filled($l['event_service_id'] ?? null) ? (int) $l['event_service_id'] : null])->values()->all();
                $this->run($action, fn () => app(RecordCostSnapshot::class)->execute(Filament::auth()->user(), $this->record->getKey(), [
                    'kind' => $kind, 'expected_event_version' => $this->displayedEventVersion, 'financial_revision_id' => $this->displayedFinanceId,
                    'expected_revision' => $kind === 'estimate' ? $this->displayedEstimateRevision : $this->displayedActualRevision,
                    'coverage_confirmed' => (bool) ($data['coverage_confirmed'] ?? false), 'note' => trim((string) $data['note']), 'lines' => $lines,
                ], $kind === 'estimate' ? $this->estimateKey : $this->actualKey), 'Costos guardados');
                if ($kind === 'estimate') $this->estimateKey = (string) Str::uuid(); else $this->actualKey = (string) Str::uuid();
                $this->capture();
            });
    }

    private function run(Action $action, \Closure $call, string $success): void
    {
        try {
            $call();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('No se guardó')->body(collect($e->errors())->flatten()->first())->persistent()->send();
            $action->halt();
        } catch (RecordWriteRejected|AuthorizationException $e) {
            // 409: someone else saved or the agreement changed. Nothing was replaced; reload to see the current version.
            Notification::make()->danger()->title('No se guardó')->body($e->getMessage().' Recarga la página para ver lo último.')->persistent()->send();
            $action->halt();
        }
        Notification::make()->success()->title($success)->send();
    }
}
