<?php

namespace App\Filament\Resources\Events\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Variants\Pages\EditServiceVariant;
use App\Filament\Support\{ChicagoTime, FinanceGate, Labels};
use App\Models\{Event, LedgerEntry};
use App\Services\Finance\{CreateFinancialRevision, FinanceSummary, RecordZellePayment};
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\{Checkbox, DatePicker, Radio, Textarea, TextInput, TimePicker};
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

// "Dinero" of one event (PAY-02, sandbox): agreed amounts, verified Zelle receipts, deposit and balance.
// Writes only through Codex's Finance services with an Idempotency-Key per attempt; payment, resources and contract stay separate.
class EventMoney extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EventResource::class;

    protected static ?string $title = 'Dinero';

    protected string $view = 'filament.pages.event-money';

    /** One key per attempt: a double click or a retry after a timeout cannot record the money twice. */
    #[Locked]
    public string $revisionKey = '';

    #[Locked]
    public string $zelleKey = '';

    public function mount(int|string $record): void
    {
        abort_unless(FinanceGate::enabled(), 403);
        $this->record = $this->resolveRecord($record);
        $this->revisionKey = (string) Str::uuid();
        $this->zelleKey = (string) Str::uuid();
    }

    public function getSubheading(): ?string
    {
        return $this->record->title.' · '.Labels::when($this->record->starts_at).' · Modo prueba: no se cobra nada real.';
    }

    public function summary(): array
    {
        return app(FinanceSummary::class)->forEvent($this->record->fresh());
    }

    public function payments()
    {
        return LedgerEntry::query()->where('event_id', $this->record->getKey())->where('environment', 'test')
            ->orderByDesc('received_at')->get()
            ->map(fn ($e) => (object) ['when' => Labels::when($e->received_at), 'amount' => Labels::money($e->amount_cents), 'method' => $e->method === 'zelle' ? 'Zelle' : 'Tarjeta',
                'reference' => $e->external_reference, 'kind' => $e->kind === 'receipt' ? 'Recibido' : ucfirst($e->kind), 'note' => $e->note,
                'by' => \App\Models\User::whereKey($e->verified_by)->value('name')]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->fixAmountsAction(), $this->zelleAction()];
    }

    public function fixAmountsAction(): Action
    {
        return Action::make('fixAmounts')->label('Fijar montos acordados')->icon('heroicon-o-lock-closed')->color('gray')
            ->visible(fn () => $this->summary()['financial_revision'] === null)
            ->requiresConfirmation()
            ->modalHeading('Fijar los montos acordados')
            ->modalDescription(fn () => 'Se guarda una copia que ya no cambia: precio de cada servicio + costo de transporte. Depósito = 50 % del total; el saldo vence 24 horas antes de la fiesta. Si después cambia el precio o la fecha, se hace una revisión nueva.')
            ->modalSubmitActionLabel('Fijar montos')
            ->action(function (Action $action) {
                $this->run($action, fn () => app(CreateFinancialRevision::class)->execute(Filament::auth()->user(), $this->record->getKey(),
                    ['expected_version' => $this->record->fresh()->version], $this->revisionKey), 'Montos fijados');
                $this->revisionKey = (string) Str::uuid();
            });
    }

    public function zelleAction(): Action
    {
        return Action::make('zelle')->label('Registrar Zelle verificado')->icon('heroicon-o-banknotes')
            ->visible(fn () => $this->summary()['financial_revision'] !== null)
            ->modalHeading('Registrar Zelle verificado')
            ->modalDescription('Escribe lo que de verdad llegó a tu banco, aunque sea distinto a lo esperado. Un aviso o una captura de la clienta no es un pago.')
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('amount')->label('Monto que llegó (USD)')->prefix('$')->required()
                        ->rule('regex:/^\d{1,7}(\.\d{1,2})?$/')->validationMessages(['regex' => 'Escribe solo el número, por ejemplo 250 o 250.50.']),
                    TextInput::make('reference')->label('Referencia de Zelle')->required()->minLength(3)->maxLength(255)
                        ->helperText('El número de confirmación. No puede repetirse en otro pago.'),
                    DatePicker::make('date')->label('Fecha en que llegó')->native()->required()->live()->maxDate(now(ChicagoTime::TZ)),
                    TimePicker::make('time')->label('Hora (Houston)')->native()->seconds(false)->required()->live(),
                    Radio::make('choice')->label('Esa hora se repite ese día: ¿cuál?')->options(ChicagoTime::CHOICES)->required()->columnSpanFull()
                        ->visible(fn (Get $get) => ChicagoTime::isAmbiguous($get('date'), $get('time'))),
                ]),
                Checkbox::make('verified')->label('Lo vi reflejado en el banco')->accepted(),
                Textarea::make('note')->label('Nota (opcional)')->maxLength(2000)->rows(2),
            ])
            ->modalSubmitActionLabel('Registrar pago')
            ->action(function (array $data, Action $action) {
                $summary = $this->summary();
                $this->run($action, fn () => app(RecordZellePayment::class)->execute(Filament::auth()->user(), $this->record->getKey(), [
                    'financial_revision_id' => $summary['financial_revision']->id,
                    'amount_cents' => EditServiceVariant::toCents($data['amount']), 'currency' => 'USD',
                    'reference' => trim($data['reference']),
                    'received_at' => ChicagoTime::toIso($data['date'], $data['time'], $data['choice'] ?? null, 'date'),
                    'verified_received' => (bool) $data['verified'], 'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                ], $this->zelleKey), 'Pago registrado');
                $this->zelleKey = (string) Str::uuid();
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
            Notification::make()->danger()->title('No se guardó')->body($e->getMessage())->persistent()->send();
            $action->halt();
        }
        Notification::make()->success()->title($success)->send();
    }
}
