<?php

namespace App\Filament\Resources\Events\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\{DocumentsGate, Labels};
use App\Models\{ClientDetailsReview, ClientDetailsSubmission, Contract, ContractTemplateVersion, FinancialRevision, PortalGrant, QuickBookingRecord, SignatureEvidence};
use App\Services\Booking\{ApproveClientDetails, ClientDetailsState};
use App\Services\Documents\{CreateContract, IssueContract};
use App\Services\Portal\{IssuePortalGrant, RevokePortalGrant};
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\{Checkbox, Radio, Textarea, TextInput};
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

// "Contrato y enlace" (DOC-01 + portal grant, sandbox): who signs, draft → issue → client link → signature evidence.
// Writes only through Codex's Documents/Portal services; a signature can only come from the client's own link.
class EventContract extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EventResource::class;

    protected static ?string $title = 'Contrato y enlace';

    protected string $view = 'filament.pages.event-contract';

    #[Locked] public string $createKey = '';
    #[Locked] public string $issueKey = '';
    #[Locked] public string $approveKey = '';
    #[Locked] public ?int $displayedEventVersion = null;

    /** Shown once after creating a link; never stored. */
    public ?array $newLink = null;

    public function mount(int|string $record): void
    {
        abort_unless(DocumentsGate::enabled(), 403);
        $this->record = $this->resolveRecord($record);
        $this->displayedEventVersion = $this->record->version;
        $this->createKey = (string) Str::uuid();
        $this->issueKey = (string) Str::uuid();
        $this->approveKey = (string) Str::uuid();
    }

    public function getSubheading(): ?string
    {
        return $this->record->title.' · '.Labels::when($this->record->starts_at).' · Modo prueba: no se envía nada a la clienta.';
    }

    public function state(): array
    {
        $e = $this->record->fresh('customer');
        $revision = FinancialRevision::where('event_id', $e->id)->orderByDesc('revision')->first();
        $template = ContractTemplateVersion::where('state', 'active')->first();
        $contract = Contract::where('event_id', $e->id)->orderByDesc('revision')->first();
        $signature = $contract ? SignatureEvidence::where('contract_id', $contract->id)->first() : null;
        $grants = PortalGrant::where('event_id', $e->id)->orderByDesc('id')->get()->map(fn ($g) => (object) [
            'id' => $g->id, 'active' => ! $g->revoked_at && $g->expires_at->isFuture(), 'details' => in_array('details.submit', $g->scopes ?? [], true),
            'expires' => Labels::when($g->expires_at), 'state' => $g->revoked_at ? 'Anulado' : ($g->expires_at->isFuture() ? 'Activo' : 'Vencido'),
            'opened' => DB::table('contract_presentations')->where('portal_grant_id', $g->id)->exists(),
        ]);
        $quick = QuickBookingRecord::where('event_id', $e->id)->exists();
        $detailsState = $quick || ClientDetailsSubmission::where('event_id', $e->id)->exists() ? ClientDetailsState::forEvent($e) : null;
        $submission = ClientDetailsSubmission::where('event_id', $e->id)->orderByDesc('revision')->first();
        $review = $submission ? ClientDetailsReview::where('submission_id', $submission->id)->first() : null;
        $blockers = [];
        if ($detailsState === 'waiting_details') $blockers[] = 'Esperando que la clienta llene sus datos con su enlace.';
        if ($detailsState === 'review_details') $blockers[] = 'Revisa y aprueba los datos de la clienta (abajo).';
        if (! $revision) $blockers[] = 'Fija los montos acordados en “Dinero”.';
        if (! $template) $blockers[] = 'Activa una versión de la plantilla en “Contrato”.';
        if (! $e->address) $blockers[] = 'Falta la dirección de la fiesta en el evento.';
        if (! $e->customer?->email) $blockers[] = 'Falta el correo de la clienta en su ficha.';
        if ($e->services()->where('operational_ready', false)->exists()) $blockers[] = 'Hay un servicio incompleto (personaje o catálogo).';

        return compact('e', 'revision', 'template', 'contract', 'signature', 'grants', 'blockers', 'quick', 'detailsState', 'submission', 'review');
    }

    protected function getHeaderActions(): array
    {
        return [$this->approveAction(), $this->createAction(), $this->issueAction(), $this->linkAction()];
    }

    public function createAction(): Action
    {
        return Action::make('create')->label('Preparar contrato')->icon('heroicon-o-document-plus')
            ->visible(function () { $s = $this->state(); return $s['blockers'] === [] && (! $s['contract'] || $s['contract']->state === 'superseded'); })
            ->modalHeading('¿Quién firma el contrato?')
            ->modalDescription('La persona adulta que contrata. Escribe nombre y apellido por separado tal como deben ir en el contrato.')
            ->fillForm(function () {
                $s = $this->state();
                $d = $s['review'] ? ($s['submission']->data ?? []) : [];
                return ['first_name' => $d['first_name'] ?? null, 'last_name' => $d['last_name'] ?? null, 'address' => $d['billing_address'] ?? null,
                    'email' => $d['email'] ?? $this->record->customer?->email, 'phone' => $d['phone'] ?? $this->record->customer?->phone];
            })
            ->schema([Grid::make(2)->schema([
                TextInput::make('first_name')->label('Nombre')->required()->maxLength(120),
                TextInput::make('last_name')->label('Apellido')->required()->maxLength(120),
                TextInput::make('address')->label('Dirección de la clienta')->required()->maxLength(255)->columnSpanFull(),
                TextInput::make('email')->label('Correo (el de su ficha)')->email()->required()->maxLength(255),
                TextInput::make('phone')->label('Teléfono')->required()->maxLength(40),
            ])])
            ->modalSubmitActionLabel('Preparar borrador')
            ->action(function (array $data, Action $action) {
                $s = $this->state();
                $this->run($action, fn () => app(CreateContract::class)->execute(Filament::auth()->user(), $this->record->getKey(), [
                    'expected_version' => $this->displayedEventVersion, 'financial_revision_id' => $s['revision']->id, 'template_version_id' => $s['template']->id,
                    'client' => array_map(fn ($v) => trim((string) $v), $data),
                ], $this->createKey), 'Borrador del contrato listo');
                $this->createKey = (string) Str::uuid();
            });
    }

    public function issueAction(): Action
    {
        return Action::make('issue')->label('Emitir contrato')->icon('heroicon-o-check')
            ->visible(fn () => $this->state()['contract']?->state === 'draft')
            ->requiresConfirmation()
            ->modalHeading('Emitir el contrato')
            ->modalDescription('Se genera el PDF con los montos y pagos de hoy y ya no cambia. Si algo cambia después, se prepara un contrato nuevo.')
            ->modalSubmitActionLabel('Emitir')
            ->action(function (Action $action) {
                $c = $this->state()['contract'];
                $this->run($action, fn () => app(IssueContract::class)->execute(Filament::auth()->user(), $c->id, ['expected_version' => $c->version], $this->issueKey), 'Contrato emitido');
                $this->issueKey = (string) Str::uuid();
            });
    }

    public function linkAction(): Action
    {
        return Action::make('link')->label('Crear enlace para la clienta')->icon('heroicon-o-link')->color('primary')
            ->visible(fn () => in_array($this->state()['contract']?->state, ['issued'], true) || in_array($this->state()['detailsState'], ['waiting_details', 'review_details'], true))
            ->modalHeading('Enlace para la clienta')
            ->modalDescription('Solo se muestra una vez. Con él la clienta lee el contrato, lo firma y ve cómo pagar.')
            ->fillForm(['days' => 7])
            ->schema([TextInput::make('days')->label('Vence en (días)')->integer()->minValue(1)->maxValue(30)->required()])
            ->modalSubmitActionLabel('Crear enlace')
            ->action(function (array $data, Action $action) {
                try {
                    $g = app(IssuePortalGrant::class)->execute(Filament::auth()->user(), $this->record->getKey(), [
                        'customer_id' => $this->record->customer_id,
                        'expires_at' => now(Labels::TZ)->addDays((int) $data['days'])->endOfDay()->format('Y-m-d\TH:i:sP'),
                        // Quick bookings also let the client send her details through the same link.
                        'scopes' => $this->state()['quick'] ? ['details.submit', 'contract.read', 'contract.sign', 'payments.read', 'checkout.create'] : ['contract.read', 'contract.sign', 'payments.read', 'checkout.create'],
                    ]);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('No se creó')->body(collect($e->errors())->flatten()->first())->persistent()->send();
                    $action->halt();
                } catch (RecordWriteRejected|AuthorizationException $e) {
                    Notification::make()->danger()->title('No se creó')->body($e->getMessage())->persistent()->send();
                    $action->halt();
                }
                $first = trim(explode(' ', (string) $this->record->customer?->name)[0] ?? '');
                $exp = Labels::when(\Carbon\CarbonImmutable::parse($g->expiresAt));
                $this->newLink = ['url' => $g->url, 'expires' => $exp,
                    'msg_es' => "¡Hola {$first}! Aquí puedes leer y firmar tu contrato de Waoo Party y ver cómo pagar tu saldo: {$g->url} (vence el ".rtrim($exp, '.')."). ¡Estamos emocionados de ser parte de tu celebración!",
                    'msg_en' => "Hi {$first}! Here you can read and sign your Waoo Party contract and see how to pay your balance: {$g->url} (expires ".rtrim($exp, '.')."). We're excited to be part of your celebration!",
                    'locale' => $this->record->customer?->locale ?? 'es'];
            });
    }

    public function approveAction(): Action
    {
        return Action::make('approve')->label('Aprobar datos de la clienta')->icon('heroicon-o-check-badge')->color('primary')
            ->visible(fn () => $this->state()['detailsState'] === 'review_details' && $this->state()['submission'] && ! $this->state()['review'])
            ->modalHeading('Aprobar los datos de la clienta')
            ->modalDescription('Se copian a su ficha y al evento (nombre, contacto y dirección). No cambia la fecha, la hora, los paquetes ni el dinero. Luego preparas el contrato.')
            ->schema(fn () => array_values(array_filter([
                Checkbox::make('travel_fee_confirmed')->label('Revisé la dirección y el ZIP: el costo de transporte cotizado sigue igual')->accepted(),
                ($this->state()['submission']->data['start_time_confirmed'] ?? true) ? null
                    : Radio::make('requested_start_resolution')->label('Pidió otra hora')->options(['keep_original' => 'Hablé con ella: se queda la hora original'])->required()
                        ->helperText('Un cambio de hora se hace aparte (flujo de cambios), no aquí.'),
                $this->record->setup_minutes === null ? TextInput::make('setup_minutes')->label('Montaje (minutos antes)')->integer()->minValue(0)->maxValue(720)->required() : null,
                $this->record->breakdown_minutes === null ? TextInput::make('breakdown_minutes')->label('Desmontaje (minutos después)')->integer()->minValue(0)->maxValue(720)->required() : null,
                Textarea::make('review_note')->label('Nota de revisión')->required()->minLength(10)->maxLength(2000)->rows(2)->placeholder('Ej.: confirmé la dirección por WhatsApp.'),
            ])))
            ->modalSubmitActionLabel('Aprobar')
            ->action(function (array $data, Action $action) {
                $sub = $this->state()['submission'];
                $input = ['expected_event_version' => $sub->event_version, 'expected_revision' => $sub->revision, 'travel_fee_confirmed' => (bool) ($data['travel_fee_confirmed'] ?? false),
                    'review_note' => trim((string) $data['review_note'])];
                foreach (['requested_start_resolution', 'setup_minutes', 'breakdown_minutes'] as $k) {
                    if (filled($data[$k] ?? null)) $input[$k] = in_array($k, ['setup_minutes', 'breakdown_minutes'], true) ? (int) $data[$k] : $data[$k];
                }
                $this->run($action, fn () => app(ApproveClientDetails::class)->execute(Filament::auth()->user(), $this->record->getKey(), $sub->id, $input, $this->approveKey), 'Datos aprobados');
                $this->approveKey = (string) Str::uuid();
                $this->record->refresh();
                $this->displayedEventVersion = $this->record->version;
            });
    }

    public function revokeAction(): Action
    {
        return Action::make('revoke')->label('Anular enlace')->color('danger')->size('sm')->link()
            ->requiresConfirmation()
            ->modalHeading('Anular este enlace')
            ->modalDescription('La clienta ya no podrá entrar con él. Si ya firmó, su firma se conserva. Puedes crear un enlace nuevo después.')
            ->modalSubmitActionLabel('Anular')
            ->action(fn (array $arguments, Action $action) => $this->run($action, fn () => app(RevokePortalGrant::class)->execute(Filament::auth()->user(), (int) $arguments['id']), 'Enlace anulado'));
    }

    public function hideLink(): void
    {
        $this->newLink = null;
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
