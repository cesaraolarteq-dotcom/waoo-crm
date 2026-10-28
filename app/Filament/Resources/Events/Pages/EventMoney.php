<?php

namespace App\Filament\Resources\Events\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Variants\Pages\EditServiceVariant;
use App\Filament\Support\{ChicagoTime, FinanceGate, Labels};
use App\Models\{Event, LedgerEntry, ZelleNotice};
use App\Services\Finance\{CreateFinancialRevision, FinanceSummary, RecordZellePayment, ReviewZelleNotice};
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
    public ?int $displayedVersion = null;

    #[Locked]
    public string $zelleKey = '';

    #[Locked]
    public string $noticeKey = '';

    /** TIP-01 owner block: one UUID per intent; the raw link is shown once (no reveal API). */
    #[Locked]
    public string $tipKey = '';

    public ?array $tipLink = null;

    public function mount(int|string $record): void
    {
        abort_unless(FinanceGate::enabled(), 403);
        $this->record = $this->resolveRecord($record);
        $this->displayedVersion = $this->record->version;
        $this->revisionKey = (string) Str::uuid();
        $this->zelleKey = (string) Str::uuid();
        $this->noticeKey = (string) Str::uuid();
        $this->tipKey = (string) Str::uuid();
    }

    public static function tipsEnabled(): bool
    {
        return config('waoo.sandbox_tips') === true && class_exists(\App\Services\Tips\ReadEventTips::class);
    }

    /** Owner totals (ReadEventTips) + the active link, if any. Unknown net stays null (shown as "Pendiente"). */
    public function tips(): ?array
    {
        if (! self::tipsEnabled()) return null;
        try {
            $read = app(\App\Services\Tips\ReadEventTips::class)->execute(Filament::auth()->user(), $this->record->id);
        } catch (\Throwable) {
            return null;
        }
        $grant = \App\Models\TipGrant::where('event_id', $this->record->id)->whereNull('revoked_at')->where('expires_at', '>', now())->latest('id')->first();

        return [...$read, 'grant' => $grant ? ['id' => $grant->id, 'version' => $grant->version, 'expires' => rtrim(Labels::when($grant->expires_at), '.')] : null,
            'can_issue' => $this->record->status === 'capacity_reserved' && ! $this->record->ends_at->isFuture() && ($this->record->responsible_system ?? 'crm') === 'crm'];
    }

    public function tipIssueAction(): Action
    {
        return Action::make('tipIssue')->label('Crear enlace de propina')->icon('heroicon-o-heart')->color('primary')
            ->modalHeading('Enlace de propina para el cliente')
            ->modalDescription('Para la fiesta ya terminada: el cliente elige 5, 10 o 15 % o el monto que quiera, con tarjeta. El 100 % es para el staff. Vence 14 días después de la fiesta. Nada se envía: tú lo mandas.')
            ->modalSubmitActionLabel('Crear enlace')
            ->action(function () {
                $rev = \App\Models\FinancialRevision::where('event_id', $this->record->id)->latest('revision')->value('id');
                try {
                    $r = app(\App\Services\Tips\IssueTipGrant::class)->execute(Filament::auth()->user(), $this->record->id, [
                        'expected_event_version' => (int) $this->displayedVersion, 'financial_revision_id' => (int) $rev,
                        'locale' => $this->record->customer?->locale === 'en' ? 'en' : 'es'], $this->tipKey);
                } catch (ValidationException $e) {
                    $this->tipKey = (string) Str::uuid();
                    Notification::make()->danger()->title('No se creó')->body(collect($e->errors())->flatten()->first())->persistent()->send();
                    return;
                } catch (RecordWriteRejected|AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                    $this->tipKey = (string) Str::uuid();
                    Notification::make()->danger()->title('No se creó')->body($e->getMessage())->persistent()->send();
                    return;
                }
                $this->tipKey = (string) Str::uuid();
                $first = trim(explode(' ', (string) $this->record->customer?->name)[0] ?? '');
                $until = rtrim(Labels::when(\Carbon\CarbonImmutable::parse($r['expires_at'])), '.');
                $this->tipLink = ['url' => $r['link'], 'expires' => $until, 'phone' => $this->record->customer?->phone, 'locale' => $this->record->customer?->locale ?? 'es',
                    'heading' => 'Enlace de propina', 'hint' => 'Cópialo ahora: por seguridad no se vuelve a mostrar. Si lo pierdes, anúlalo y crea otro.',
                    'msg_es' => "¡Hola {$first}! Gracias por celebrar con Waoo Party. Si quieres agradecerle al equipo de tu fiesta, aquí puedes dejar una propina (el 100 % es para el staff): {$r['link']}",
                    'msg_en' => "Hi {$first}! Thank you for celebrating with Waoo Party. If you'd like to thank your party's team, you can leave a tip here (100% goes to the staff): {$r['link']}"];
            });
    }

    public function tipRevokeAction(): Action
    {
        return Action::make('tipRevoke')->label('Anular enlace')->color('danger')->size('sm')->link()->requiresConfirmation()
            ->modalHeading('Anular el enlace de propina')
            ->modalDescription('El cliente ya no podrá dejar propina con ese enlace. Las propinas ya pagadas se conservan.')
            ->action(function (array $arguments) {
                try {
                    app(\App\Services\Tips\RevokeTipGrant::class)->execute(Filament::auth()->user(), (int) ($arguments['grant'] ?? 0), ['expected_version' => (int) ($arguments['version'] ?? 0)], $this->tipKey);
                } catch (ValidationException|RecordWriteRejected|AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                    $this->tipKey = (string) Str::uuid();
                    Notification::make()->danger()->title('No se anuló')->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())->persistent()->send();
                    return;
                }
                $this->tipKey = (string) Str::uuid();
                $this->tipLink = null;
                Notification::make()->success()->title('Enlace de propina anulado')->send();
            });
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

    /** Client Zelle notices for this event (claims only). The review shows what the owner decided and what really arrived. */
    public function notices()
    {
        if (! \App\Filament\Support\DocumentsGate::enabled()) {
            return collect();
        }
        $current = $this->summary()['financial_revision']?->id;

        return ZelleNotice::query()->where('event_id', $this->record->getKey())->with('review.ledgerEntry')->orderByDesc('id')->get()
            ->map(fn ($n) => (object) ['id' => $n->id, 'amount' => Labels::money($n->amount_cents), 'when' => Labels::when($n->submitted_at),
                'sent' => $n->sent_at ? Labels::when($n->sent_at) : null, 'reference' => $n->reference, 'payer' => $n->payer_name, 'note' => $n->note,
                'screenshot' => \App\Models\ZelleNoticeScreenshot::where('notice_id', $n->id)->exists() ? url('/admin/api/zelle-notices/'.$n->id.'/screenshot') : null,
                'old_amounts' => $current !== null && $n->financial_revision_id !== $current,
                'state' => $n->review?->decision ?? 'pending', 'review_note' => $n->review?->review_note,
                'real' => $n->review?->ledgerEntry ? Labels::money($n->review->ledgerEntry->amount_cents) : null,
                'reviewed' => $n->review ? Labels::when($n->review->reviewed_at).' · '.(\App\Models\User::whereKey($n->review->reviewed_by)->value('name') ?? '') : null]);
    }

    /** Only a still-pending notice of this same event; anything else hides the buttons. */
    private function notice(array $arguments): ?ZelleNotice
    {
        return ZelleNotice::query()->where('event_id', $this->record->getKey())->whereDoesntHave('review')->find((int) ($arguments['id'] ?? 0));
    }

    private static function claim(?ZelleNotice $n): string
    {
        if (! $n) {
            return '';
        }

        return 'El cliente dice que envió '.Labels::money($n->amount_cents).($n->reference ? ' (ref. '.$n->reference.')' : '').($n->payer_name ? ' a nombre de '.$n->payer_name : '').'. ';
    }

    public function verifyNoticeAction(): Action
    {
        return Action::make('verifyNotice')->label('Verificar')->icon('heroicon-o-check-circle')->color('success')->size('sm')
            ->visible(fn (array $arguments) => \App\Filament\Support\DocumentsGate::enabled() && $this->notice($arguments) !== null)
            ->modalHeading('Verificar el aviso de Zelle')
            ->modalDescription(fn (array $arguments) => self::claim($this->notice($arguments)).'Escribe lo que de verdad llegó a tu banco, aunque sea distinto. Eso es lo único que cuenta como pago.')
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('amount')->label('Monto que llegó (USD)')->prefix('$')->required()
                        ->rule('regex:/^\d{1,7}(\.\d{1,2})?$/')->validationMessages(['regex' => 'Escribe solo el número, por ejemplo 250 o 250.50.']),
                    TextInput::make('reference')->label('Referencia de Zelle en tu banco (opcional)')->minLength(3)->maxLength(255)
                        ->helperText('Si ya lo registraste a mano, usa la misma referencia: no se cuenta dos veces.'),
                    DatePicker::make('date')->label('Fecha en que llegó')->native()->required()->live()->maxDate(now(ChicagoTime::TZ)),
                    TimePicker::make('time')->label('Hora (Houston)')->native()->seconds(false)->required()->live(),
                    Radio::make('choice')->label('Esa hora se repite ese día: ¿cuál?')->options(ChicagoTime::CHOICES)->required()->columnSpanFull()
                        ->visible(fn (Get $get) => ChicagoTime::isAmbiguous($get('date'), $get('time'))),
                ]),
                Checkbox::make('verified')->label('Lo vi reflejado en el banco')->accepted(),
                Textarea::make('review_note')->label('Nota de revisión (solo la ves tú)')->required()->minLength(10)->maxLength(2000)->rows(2)
                    ->helperText('Por ejemplo: “Llegó completo al banco”, o por qué el monto es distinto.'),
            ])
            ->modalSubmitActionLabel('Verificar y registrar pago')
            ->action(function (array $data, array $arguments, Action $action) {
                $notice = $this->notice($arguments) ?? $action->halt();
                $this->run($action, fn () => app(ReviewZelleNotice::class)->execute(Filament::auth()->user(), $this->record->getKey(), $notice->id, [
                    'decision' => 'verified', 'review_note' => trim($data['review_note']),
                    'financial_revision_id' => $this->summary()['financial_revision']?->id,
                    'amount_cents' => EditServiceVariant::toCents($data['amount']), 'currency' => 'USD', 'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                    'received_at' => ChicagoTime::toIso($data['date'], $data['time'], $data['choice'] ?? null, 'date'),
                    'verified_received' => (bool) $data['verified'],
                ], $this->noticeKey), 'Aviso verificado y pago registrado');
                $this->noticeKey = (string) Str::uuid();
            });
    }

    public function rejectNoticeAction(): Action
    {
        return Action::make('rejectNotice')->label('Rechazar')->icon('heroicon-o-x-circle')->color('danger')->size('sm')
            ->visible(fn (array $arguments) => \App\Filament\Support\DocumentsGate::enabled() && $this->notice($arguments) !== null)
            ->modalHeading('Rechazar el aviso de Zelle')
            ->modalDescription(fn (array $arguments) => self::claim($this->notice($arguments)).'Rechazar no registra ni quita ningún pago. El cliente solo ve que no se pudo verificar.')
            ->schema([
                Textarea::make('review_note')->label('Motivo (solo lo ves tú)')->required()->minLength(10)->maxLength(2000)->rows(2)
                    ->helperText('Por ejemplo: “No aparece en el banco después de 2 días”.'),
            ])
            ->modalSubmitActionLabel('Rechazar aviso')
            ->action(function (array $data, array $arguments, Action $action) {
                $notice = $this->notice($arguments) ?? $action->halt();
                $this->run($action, fn () => app(ReviewZelleNotice::class)->execute(Filament::auth()->user(), $this->record->getKey(), $notice->id, [
                    'decision' => 'rejected', 'review_note' => trim($data['review_note']),
                ], $this->noticeKey), 'Aviso rechazado');
                $this->noticeKey = (string) Str::uuid();
            });
    }

    protected function getHeaderActions(): array
    {
        return [$this->fixAmountsAction(), $this->zelleAction()];
    }

    public function fixAmountsAction(): Action
    {
        return Action::make('fixAmounts')->label('Fijar montos acordados')->icon('heroicon-o-lock-closed')->color('gray')
            ->visible(function () {
                $event = $this->record->fresh();
                $revision = $this->summary()['financial_revision'];

                return $event->status === 'draft' && ($revision === null || $revision->event_version !== $event->version);
            })
            ->requiresConfirmation()
            ->modalHeading('Fijar los montos acordados')
            ->modalDescription(fn () => 'Se guarda una copia que ya no cambia: precio de cada servicio + costo de transporte. Depósito = 50 % del total; el saldo vence 24 horas antes de la fiesta. Si después cambia el precio o la fecha, se hace una revisión nueva.')
            ->modalSubmitActionLabel('Fijar montos')
            ->action(function (Action $action) {
                $this->run($action, fn () => app(CreateFinancialRevision::class)->execute(Filament::auth()->user(), $this->record->getKey(),
                    ['expected_version' => $this->displayedVersion], $this->revisionKey), 'Montos fijados');
                $this->revisionKey = (string) Str::uuid();
            });
    }

    public function zelleAction(): Action
    {
        return Action::make('zelle')->label('Registrar Zelle verificado')->icon('heroicon-o-banknotes')
            ->visible(fn () => $this->summary()['financial_revision'] !== null)
            ->modalHeading('Registrar Zelle verificado')
            ->modalDescription('Escribe lo que de verdad llegó a tu banco, aunque sea distinto a lo esperado. Un aviso o una captura del cliente no es un pago.')
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('amount')->label('Monto que llegó (USD)')->prefix('$')->required()
                        ->rule('regex:/^\d{1,7}(\.\d{1,2})?$/')->validationMessages(['regex' => 'Escribe solo el número, por ejemplo 250 o 250.50.']),
                    TextInput::make('reference')->label('Referencia de Zelle (opcional)')->minLength(3)->maxLength(255)
                        ->helperText('Sin referencia se revisan el monto y la fecha para evitar duplicados.'),
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
                    'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
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
        } catch (RecordWriteRejected|AuthorizationException|\App\Exceptions\ResponsibilityRejected $e) {
            Notification::make()->danger()->title('No se guardó')->body($e->getMessage())->persistent()->send();
            $action->halt();
        }
        Notification::make()->success()->title($success)->send();
    }
}
