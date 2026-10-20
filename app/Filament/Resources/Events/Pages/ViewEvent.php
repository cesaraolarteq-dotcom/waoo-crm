<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\{FinanceGate, Labels};
use App\Models\{PriceVersion, Resource as Unit, ServiceVariant, User};
use App\Services\Finance\FinanceSummary;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;

// Event file (read-only), for drafts and booked events alike: details, services + characters, assigned units,
// money and the audit history. Changes happen in their own screens (edit draft, Apartar recursos, Dinero).
class ViewEvent extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EventResource::class;

    protected string $view = 'filament.pages.event-file';

    public const ACTIONS = [
        'event.created' => 'Creó el borrador', 'event.draft_updated' => 'Editó el borrador', 'booking.capacity_reserved' => 'Apartó staff y trajes',
        'finance.revision_created' => 'Fijó los montos', 'payment.zelle_received' => 'Registró un Zelle', 'payment.capacity_linked' => 'Vinculó el pago con los recursos',
        'portal.zelle_notice_submitted' => 'La clienta avisó que envió un Zelle', 'payment.zelle_notice_reviewed' => 'Revisó un aviso de Zelle de la clienta',
        'cost.snapshot_recorded' => 'Registró costos',
        'mail.planned' => 'Programó un correo', 'mail.policy_updated' => 'Cambió la pausa de correos',
    ];

    /** Zelle notice reviews say which way they went; amounts stay on the Dinero screen. */
    public static function historyLabel(object $a): string
    {
        $label = self::ACTIONS[$a->action] ?? $a->action;
        if ($a->action === 'payment.zelle_notice_reviewed') {
            $decision = json_decode((string) $a->after, true)['decision'] ?? null;
            $label .= match ($decision) { 'verified' => ': verificado', 'rejected' => ': rechazado', default => '' };
        }

        return $label;
    }

    /** Event version shown on this page; "Crear cotización" snapshots exactly this version or is refused (409). */
    /** C3 A cutover evidence: binding hash + review version read when the modal opened, one UUID per intent. */
    #[\Livewire\Attributes\Locked]
    public ?string $cutBinding = null;
    #[\Livewire\Attributes\Locked]
    public ?int $cutVersion = null;
    #[\Livewire\Attributes\Locked]
    public ?int $cutDocumentId = null;
    #[\Livewire\Attributes\Locked]
    public ?int $cutBalance = null;
    #[\Livewire\Attributes\Locked]
    public string $cutKey = '';

    /** C3 B3b original-agreement link: values ReadConfirmedAgreement returned when the modal opened, one UUID per intent. */
    #[\Livewire\Attributes\Locked]
    public ?array $origPin = null;
    #[\Livewire\Attributes\Locked]
    public string $origKey = '';
    #[\Livewire\Attributes\Locked]
    public string $origNote = '';

    /** STAFF-01 instructions: versions the modal showed (ReadStaffJobInstructions) and one UUID per save intent. */
    #[\Livewire\Attributes\Locked]
    public ?int $instrEventVersion = null;
    #[\Livewire\Attributes\Locked]
    public ?int $instrVersion = null;
    #[\Livewire\Attributes\Locked]
    public string $instrKey = '';

    #[\Livewire\Attributes\Locked]
    public ?int $displayedVersion = null;

    #[\Livewire\Attributes\Locked]
    public string $quoteKey = '';

    /** MAIL-01: client record version and mail pause version shown on this page; changes are refused if they moved. */
    #[\Livewire\Attributes\Locked]
    public ?int $displayedCustomerVersion = null;

    #[\Livewire\Attributes\Locked]
    public ?int $mailPolicyVersion = null;

    #[\Livewire\Attributes\Locked]
    public string $mailKey = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->displayedVersion = $this->record->version;
        $this->quoteKey = (string) \Illuminate\Support\Str::uuid();
        $this->displayedCustomerVersion = $this->record->customer?->version;
        $this->mailKey = (string) \Illuminate\Support\Str::uuid();
        $this->mailPolicyVersion = $this->mail()['policy']['version'] ?? null;
    }

    /** This event's messages through ReadOutboundMail (owner-only, no body/recipient), or null while mail is off. */
    public function mail(): ?array
    {
        if (! \App\Filament\Support\MailUi::enabled()) {
            return null;
        }
        try {
            $read = app(\App\Services\Mail\ReadOutboundMail::class)->forEvent(\Filament\Facades\Filament::auth()->user(), $this->record->getKey(), ['per_page' => 50]);
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return null;
        }
        $read['rows'] = collect($read['data'])->map(fn ($m) => (object) [
            'template' => \App\Filament\Support\MailUi::TEMPLATES[$m['template_key']] ?? $m['template_key'],
            'when' => Labels::when(\Carbon\CarbonImmutable::parse($m['due_at'])), 'lang' => strtoupper($m['locale']),
            'status' => \App\Filament\Support\MailUi::STATUS[$m['status']] ?? $m['status'], 'color' => \App\Filament\Support\MailUi::STATUS_COLOR[$m['status']] ?? 'gray',
            'error' => $m['error_code'] ? (\App\Filament\Support\MailUi::ERRORS[$m['error_code']] ?? $m['error_code']) : null,
        ])->all();

        return $read;
    }

    /** Pause/resume this event's reminders with the version shown; a stale screen is refused and refreshed. */
    public function toggleMailPause(): void
    {
        $shown = $this->mail();
        if ($shown === null) {
            return;
        }
        try {
            $r = app(\App\Services\Mail\PauseEventMail::class)->execute(\Filament\Facades\Filament::auth()->user(), $this->record->getKey(),
                ['expected_version' => (int) $this->mailPolicyVersion, 'paused' => ! $shown['policy']['paused']], $this->mailKey);
        } catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Validation\ValidationException|\Illuminate\Auth\Access\AuthorizationException|\App\Exceptions\ResponsibilityRejected $e) {
            $this->mailKey = (string) \Illuminate\Support\Str::uuid();
            $this->mailPolicyVersion = $this->mail()['policy']['version'] ?? null;
            \Filament\Notifications\Notification::make()->danger()->title('No se cambió')->body('Alguien cambió la pausa de correos de este evento. Revisa el estado actual.')->persistent()->send();

            return;
        }
        $this->mailPolicyVersion = $r['version'];
        $this->mailKey = (string) \Illuminate\Support\Str::uuid();
        \Filament\Notifications\Notification::make()->success()->title($r['paused'] ? 'Correos en pausa' : 'Correos reanudados')
            ->body($r['paused'] ? 'Lo programado no sale mientras esté en pausa.' : 'Lo que ya se omitió no se vuelve a enviar.')->send();
    }

    /** Active portal links of this event and client that a reminder can carry (never issues or rotates a link). */
    public function mailGrantOptions(): array
    {
        return \App\Models\PortalGrant::query()->where('event_id', $this->record->getKey())->where('customer_id', $this->record->customer_id)
            ->whereNull('quote_revision_id')->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('expires_at')->get()
            ->filter(fn ($g) => in_array('payments.read', $g->scopes ?? [], true))
            ->mapWithKeys(fn ($g) => [$g->id => 'Enlace del '.Labels::when($g->created_at).' · vence '.Labels::when($g->expires_at).(in_array('checkout.create', $g->scopes, true) ? ' · permite pagar' : ' · solo ver')])->all();
    }

    public function getTitle(): string
    {
        return $this->record->title;
    }

    public function getSubheading(): ?string
    {
        return ($this->record->customer?->name ?? '').' · '.Labels::when($this->record->starts_at).' – '.$this->record->ends_at->setTimezone(Labels::TZ)->locale('es')->translatedFormat('g:i a');
    }

    protected function getHeaderActions(): array
    {
        $e = $this->record;

        return [
            Action::make('edit')->label('Editar borrador')->color('gray')->visible(fn () => EventResource::canEdit($e))->url(fn () => EventResource::getUrl('edit', ['record' => $e])),
            Action::make('money')->label('Dinero')->icon('heroicon-o-banknotes')->color('gray')->visible(fn () => FinanceGate::enabled())->url(fn () => EventResource::getUrl('money', ['record' => $e])),
            Action::make('quoteView')->label('Ver cotización')->icon('heroicon-o-document-currency-dollar')->color('gray')
                ->visible(fn () => \App\Filament\Support\QuoteForm::enabled() && \App\Models\Quote::where('event_id', $e->id)->exists())
                ->url(fn () => \App\Filament\Pages\QuoteDetail::getUrl(['quote' => \App\Models\Quote::where('event_id', $e->id)->value('id')])),
            Action::make('quoteCreate')->label('Crear cotización')->icon('heroicon-o-document-currency-dollar')->color('gray')
                ->visible(fn () => \App\Filament\Support\QuoteForm::enabled() && $e->status === 'draft' && ! \App\Models\Quote::where('event_id', $e->id)->exists())
                ->modalHeading('Crear cotización')
                ->modalDescription(fn () => 'Se toma este borrador tal como lo ves (versión '.$this->displayedVersion.'): paquetes, personajes, transporte y fecha. El total y el depósito los calcula el sistema.')
                ->fillForm(fn () => ['locale' => $e->customer?->locale ?? 'es'])
                ->schema(\App\Filament\Support\QuoteForm::schema())
                ->modalSubmitActionLabel('Crear cotización')
                ->action(function (array $data, Action $action) use ($e) {
                    try {
                        $rev = app(\App\Services\Quotes\CreateQuote::class)->execute(\Filament\Facades\Filament::auth()->user(),
                            ['event_id' => $e->id, 'expected_event_version' => (int) $this->displayedVersion, ...\App\Filament\Support\QuoteForm::input($data)], $this->quoteKey);
                    } catch (\Illuminate\Validation\ValidationException $ex) {
                        \Filament\Notifications\Notification::make()->danger()->title('No se creó')->body(collect($ex->errors())->flatten()->first())->persistent()->send();
                        $action->halt();
                    } catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Auth\Access\AuthorizationException|\App\Exceptions\ResponsibilityRejected $ex) {
                        \Filament\Notifications\Notification::make()->danger()->title('No se creó')->body($ex->getMessage().' Recarga la página.')->persistent()->send();
                        $action->halt();
                    }
                    $this->quoteKey = (string) \Illuminate\Support\Str::uuid();
                    $this->redirect(\App\Filament\Pages\QuoteDetail::getUrl(['quote' => $rev->quote_id]));
                }),
            $this->staffInstructionsAction(),
            $this->cutoverEvidenceAction(),
            $this->originalAgreementLinkAction(),
            Action::make('costs')->label('Costos y margen')->icon('heroicon-o-chart-bar')->color('gray')->visible(fn () => FinanceGate::enabled() && class_exists(\App\Services\Costs\RecordCostSnapshot::class))->url(fn () => EventResource::getUrl('costs', ['record' => $e])),
            Action::make('mailPlan')->label('Programar recordatorios')->icon('heroicon-o-envelope')->color('gray')
                ->visible(fn () => \App\Filament\Support\MailUi::enabled() && $e->status === 'capacity_reserved' && ! \App\Models\ImportedProjectLink::where('event_id', $e->id)->exists())
                ->modalHeading('Programar recordatorios por correo')
                ->modalDescription('Se programan con los datos que ves ahora (evento y ficha de la clienta). No se envía nada hasta que César active los correos y apruebe los textos; mientras tanto solo pueden ir a la lista de prueba.')
                ->schema([
                    \Filament\Forms\Components\Select::make('portal_grant_id')->label('Enlace que lleva el correo')->native(false)->required()
                        ->options(fn () => $this->mailGrantOptions())->helperText('Debe durar más que el último recordatorio. Para el de saldo, debe permitir pagar.'),
                    \Filament\Forms\Components\CheckboxList::make('templates')->label('Cuáles')->required()->options(\App\Filament\Support\MailUi::TEMPLATES)
                        ->default(array_keys(\App\Filament\Support\MailUi::TEMPLATES))
                        ->helperText('Saldo: 24 h antes de que venza. Una semana: 7 días antes de la fiesta. Gracias: al día siguiente, a la hora en que terminó.'),
                ])
                ->modalSubmitActionLabel('Programar')
                ->action(function (array $data, Action $action) use ($e) {
                    try {
                        $r = app(\App\Services\Mail\PlanEventMail::class)->execute(\Filament\Facades\Filament::auth()->user(), $e->id, [
                            'expected_event_version' => (int) $this->displayedVersion, 'expected_customer_version' => (int) $this->displayedCustomerVersion,
                            'portal_grant_id' => (int) $data['portal_grant_id'], 'templates' => array_values($data['templates']),
                        ], $this->mailKey);
                    } catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Validation\ValidationException|\Illuminate\Auth\Access\AuthorizationException|\App\Exceptions\ResponsibilityRejected $ex) {
                        $this->mailKey = (string) \Illuminate\Support\Str::uuid();
                        \Filament\Notifications\Notification::make()->danger()->title('No se programó nada')->body(\App\Filament\Support\MailUi::refusal($ex))->persistent()->send();
                        $action->halt();
                    }
                    $this->mailKey = (string) \Illuminate\Support\Str::uuid();
                    $skipped = collect($r['skipped'] ?? [])->map(fn ($x) => (\App\Filament\Support\MailUi::TEMPLATES[$x['template']] ?? $x['template']).': '.(\App\Filament\Support\MailUi::SKIPPED[$x['reason']] ?? $x['reason']))->implode(' · ');
                    \Filament\Notifications\Notification::make()->success()->title(count($r['message_ids']).' '.(count($r['message_ids']) === 1 ? 'correo programado' : 'correos programados'))
                        ->body(($skipped ? 'No se programó: '.$skipped.'. ' : '').'Programado no es enviado.')->send();
                }),
            Action::make('contract')->label('Contrato y enlace')->icon('heroicon-o-document-text')->color('gray')->visible(fn () => \App\Filament\Support\DocumentsGate::enabled())->url(fn () => EventResource::getUrl('contract', ['record' => $e])),
            Action::make('confirm')->label('Apartar recursos y confirmar')->visible(fn () => ConfirmCapacity::canAccessFor($e))->url(fn () => EventResource::getUrl('confirm', ['record' => $e])),
        ];
    }

    /** Real contract state for the header pill (imported events keep their contract in HoneyBook). */
    /** HoneyBook party: owner's evidence for switch-over day (original signed PDF + money review + retirement attestations). */
    public function cutoverEvidenceAction(): Action
    {
        $e = $this->record;
        $read = fn () => app(\App\Services\Imports\ReadHoneyBookCutoverReview::class)->execute(\Filament\Facades\Filament::auth()->user(), $e->id);

        return Action::make('cutoverEvidence')->label('Evidencia para el cambio')->icon('heroicon-o-document-check')->color('gray')
            ->visible(fn () => \App\Filament\Support\CutoverUi::enabled() && $e->getAttribute('responsible_system') === 'honeybook')
            ->modalHeading('Evidencia para el día del cambio')
            ->modalDescription(function () use ($read) {
                try { $v = $read(); } catch (\Throwable) { return 'No se pudo leer el estado de esta fiesta.'; }
                $s = $v['source'];
                $money = 'Según HoneyBook (no verificado en tu banco): total '.\App\Filament\Support\Labels::money($s['total_cents']).', pagado '.\App\Filament\Support\Labels::money($s['net_paid_cents'])
                    .', saldo '.\App\Filament\Support\Labels::money($s['balance_cents']).'. Contrato en el archivo: '.($s['contract_signed'] ? 'firmado' : 'sin firmar').'.';
                $missing = collect($v['missing'])->map(fn ($c) => \App\Filament\Support\CutoverUi::label($c))->implode(' · ');

                return $money.($missing ? ' Falta: '.$missing : ' Esta fiesta tiene toda su evidencia.').' Esto es solo evidencia: no pasa nada al CRM ni apaga HoneyBook.';
            })
            ->fillForm(function () use ($read) {
                $v = $read();
                $this->cutBinding = $v['binding_sha256'];
                $this->cutVersion = (int) $v['expected_version'];
                $this->cutDocumentId = $v['document']['id'] ?? null;
                $this->cutBalance = $v['source']['balance_cents'];
                $this->cutKey = (string) \Illuminate\Support\Str::uuid();

                return ['reuse_document' => $this->cutDocumentId !== null];
            })
            ->schema([
                \Filament\Forms\Components\Checkbox::make('reuse_document')->label('Usar el PDF del contrato que ya subí')->live()
                    ->visible(fn () => $this->cutDocumentId !== null),
                \Filament\Forms\Components\FileUpload::make('file')->label('Contrato firmado original (PDF de HoneyBook)')->acceptedFileTypes(['application/pdf'])->maxSize(5120)
                    ->storeFiles(false)->required(fn ($get) => ! $get('reuse_document'))->visible(fn ($get) => ! $get('reuse_document'))
                    ->helperText('Bájalo de HoneyBook (Imprimir → Guardar como PDF). Se guarda cifrado; no se puede descargar desde el CRM.'),
                \Filament\Forms\Components\Checkbox::make('owner_attests_signed_original')->label('Este PDF es el contrato original firmado por la clienta')->accepted(),
                \Filament\Schemas\Components\Grid::make(2)->schema([
                    \Filament\Forms\Components\DatePicker::make('observed_date')->label('Día en que revisaste las cuentas en HoneyBook')->native(false)->required()->maxDate(now(\App\Filament\Support\ChicagoTime::TZ))->live(),
                    \Filament\Forms\Components\TimePicker::make('observed_time')->label('Hora (Houston)')->seconds(false)->required()->live(),
                    \Filament\Forms\Components\Radio::make('observed_choice')->label('Esa hora se repite ese día (cambio de horario): ¿cuál?')->options(\App\Filament\Support\ChicagoTime::CHOICES)->required()->columnSpanFull()
                        ->visible(fn ($get) => \App\Filament\Support\ChicagoTime::isAmbiguous(substr((string) $get('observed_date'), 0, 10) ?: null, substr((string) $get('observed_time'), 0, 5) ?: null)),
                ]),
                \Filament\Forms\Components\Checkbox::make('owner_money_reviewed')->label('Revisé en HoneyBook el total, lo pagado y el saldo de esta fiesta')->accepted(),
                \Filament\Forms\Components\TextInput::make('source_reference')->label('Referencia de tu revisión')->required()->minLength(5)->maxLength(255)
                    ->placeholder('Ej.: HoneyBook › Payments, captura 2026-11-02 9:10'),
                \Filament\Forms\Components\Textarea::make('owner_note')->label('Nota (solo la ves tú)')->required()->minLength(5)->maxLength(1000)->rows(2),
                \Filament\Schemas\Components\Grid::make(2)->visible(fn () => ($this->cutBalance ?? 0) > 0)->schema([
                    \Filament\Forms\Components\DatePicker::make('due_date')->label('El saldo vence el (para el CRM)')->native(false)->live(),
                    \Filament\Forms\Components\TimePicker::make('due_time')->label('Hora (Houston)')->seconds(false)->live(),
                    \Filament\Forms\Components\Radio::make('due_choice')->label('Esa hora se repite ese día (cambio de horario): ¿cuál?')->options(\App\Filament\Support\ChicagoTime::CHOICES)->required()->columnSpanFull()
                        ->visible(fn ($get) => \App\Filament\Support\ChicagoTime::isAmbiguous(substr((string) $get('due_date'), 0, 10) ?: null, substr((string) $get('due_time'), 0, 5) ?: null)),
                ]),
                \Filament\Forms\Components\Checkbox::make('honeybook_automations_disabled')->label('Ya apagué los avisos automáticos de HoneyBook para esta fiesta'),
                \Filament\Forms\Components\Checkbox::make('external_collections_retired')->label('Ya quité los cobros pendientes de HoneyBook para esta fiesta'),
            ])
            ->modalSubmitActionLabel('Guardar evidencia')
            ->action(function (array $data, Action $action) use ($e) {
                // An hour that repeats on the fall-back day needs the owner's explicit CDT/CST choice; never picked silently.
                $tz = fn ($d, $t, $choice, $field) => \App\Filament\Support\ChicagoTime::toIso(substr((string) $d, 0, 10), substr((string) $t, 0, 5), $choice ?: null, $field);
                $file = $data['file'] ?? null;
                if (is_array($file)) $file = reset($file) ?: null;
                $input = ['expected_version' => (int) $this->cutVersion, 'expected_binding_sha256' => (string) $this->cutBinding,
                    'owner_attests_signed_original' => (bool) ($data['owner_attests_signed_original'] ?? false), 'owner_money_reviewed' => (bool) ($data['owner_money_reviewed'] ?? false),
                    'source_observed_at' => $tz($data['observed_date'], $data['observed_time'], $data['observed_choice'] ?? null, 'observed_date'),
                    'source_reference' => trim((string) $data['source_reference']), 'owner_note' => trim((string) $data['owner_note']),
                    'crm_balance_due_at' => filled($data['due_date'] ?? null) && filled($data['due_time'] ?? null) ? $tz($data['due_date'], $data['due_time'], $data['due_choice'] ?? null, 'due_date') : null,
                    'honeybook_automations_disabled' => (bool) ($data['honeybook_automations_disabled'] ?? false), 'external_collections_retired' => (bool) ($data['external_collections_retired'] ?? false),
                    ...(($data['reuse_document'] ?? false) && $this->cutDocumentId ? ['original_document_id' => $this->cutDocumentId] : ($file ? ['file' => $file] : []))];
                try {
                    app(\App\Services\Imports\SaveHoneyBookCutoverReview::class)->execute(\Filament\Facades\Filament::auth()->user(), $e->id, $input, $this->cutKey);
                } catch (\Illuminate\Validation\ValidationException $ex) {
                    $this->cutKey = (string) \Illuminate\Support\Str::uuid();
                    \Filament\Notifications\Notification::make()->danger()->title('No se guardó')->body(collect($ex->errors())->flatten()->first())->persistent()->send();
                    $action->halt();
                } catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $ex) {
                    $this->cutKey = (string) \Illuminate\Support\Str::uuid();
                    \Filament\Notifications\Notification::make()->danger()->title('No se guardó')->body($ex->getMessage().' Vuelve a abrir para ver lo actual.')->persistent()->send();
                    $action->halt();
                }
                $this->cutKey = '';
                \Filament\Notifications\Notification::make()->success()->title('Evidencia guardada')->body('Queda lista para el día del cambio. HoneyBook sigue cobrando esta fiesta.')->send();
            });
    }

    /**
     * Read-only client link for an agreement that started in HoneyBook (docs/OPENING-CLIENT-PORTAL-API.md). Only works once
     * the party's original agreement is effective in the CRM; before the switch-over the modal explains why it cannot.
     * The client can only view the agreement, recorded payments and the original PDF: no signing, paying or details form.
     */
    public function originalAgreementLinkAction(): Action
    {
        $e = $this->record;
        $gates = fn () => config('waoo.sandbox_cutover_bridge') === true && \App\Filament\Support\CutoverUi::enabled() && \App\Filament\Support\DocumentsGate::enabled()
            && class_exists(\App\Services\Portal\IssueOpeningPortalGrant::class);

        return Action::make('originalAgreementLink')->label('Enlace del acuerdo original')->icon('heroicon-o-link')->color('gray')
            ->visible(fn () => $gates() && \App\Models\ImportedProjectLink::where('event_id', $e->id)->exists())
            ->modalHeading('Enlace del acuerdo original')
            ->fillForm(function () use ($e) {
                // Pin exactly what the owner sees; the issue call sends these values, never a fresh read.
                $this->origPin = null; $this->origNote = '';
                try {
                    $a = app(\App\Services\Agreements\ReadConfirmedAgreement::class)->execute(\Filament\Facades\Filament::auth()->user(), $e->id);
                    $this->origPin = ['customer_id' => (int) $e->customer_id, 'event_version' => (int) $a['event_version'],
                        'financial_revision_id' => (int) $a['financial_revision_id'], 'snapshot' => (string) $a['snapshot_sha256'], 'total' => $a['total_cents']];
                    $this->origKey = (string) \Illuminate\Support\Str::uuid();
                } catch (\Throwable $ex) {
                    $this->origNote = $ex->getMessage();
                }

                return ['days' => 14];
            })
            ->modalDescription(fn () => $this->origPin === null
                ? 'Todavía no se puede crear: esta fiesta sigue a cargo de HoneyBook o su acuerdo necesita revisión.'.($this->origNote !== '' ? ' ('.$this->origNote.')' : '')
                : 'La clienta podrá ver su fiesta, lo acordado en HoneyBook (total '.Labels::money($this->origPin['total']).'), sus pagos registrados y descargar el PDF original. '
                    .'Solo consulta: no puede firmar, pagar ni cambiar nada. No se le envía nada: tú copias el enlace y se lo mandas.')
            ->schema([
                \Filament\Forms\Components\Select::make('days')->label('El enlace vence en')->options([7 => '7 días', 14 => '14 días', 30 => '30 días'])->required()
                    ->visible(fn () => $this->origPin !== null),
            ])
            ->modalSubmitActionLabel('Crear enlace')
            ->modalSubmitAction(fn (Action $a) => $a->disabled($this->origPin === null))
            ->action(function (array $data, Action $action) use ($e) {
                if ($this->origPin === null) { $action->halt(); }
                $p = $this->origPin;
                try {
                    $r = app(\App\Services\Portal\IssueOpeningPortalGrant::class)->execute(\Filament\Facades\Filament::auth()->user(), $e->id, [
                        'customer_id' => $p['customer_id'], 'expected_event_version' => $p['event_version'], 'financial_revision_id' => $p['financial_revision_id'],
                        'expected_snapshot_sha256' => $p['snapshot'], 'expires_at' => now()->addDays(in_array((int) $data['days'], [7, 14, 30], true) ? (int) $data['days'] : 14)->toIso8601String(),
                    ], $this->origKey);
                } catch (\Illuminate\Validation\ValidationException $ex) {
                    $this->origKey = (string) \Illuminate\Support\Str::uuid();
                    \Filament\Notifications\Notification::make()->danger()->title('No se creó el enlace')->body(collect($ex->errors())->flatten()->first())->persistent()->send();
                    $action->halt();
                } catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $ex) {
                    $this->origKey = (string) \Illuminate\Support\Str::uuid();
                    \Filament\Notifications\Notification::make()->danger()->title('No se creó el enlace')->body($ex->getMessage().' Vuelve a abrir para ver lo actual.')->persistent()->send();
                    $action->halt();
                }
                $this->origKey = ''; $this->origPin = null;
                $until = \Carbon\CarbonImmutable::parse($r['expires_at'])->setTimezone('America/Chicago')->locale('es')->translatedFormat('j M Y');
                \Filament\Notifications\Notification::make()->success()->title('Enlace listo (solo consulta)')
                    ->body('Cópialo y mándaselo a la clienta. Vence el '.$until.'. '.$r['link'])->persistent()->send();
            });
    }

    /** What the assigned staff see on their week page: parking, access, what to bring, notes, contact of the day. */
    public function staffInstructionsAction(): Action
    {
        $e = $this->record;

        return Action::make('staffInstructions')->label('Indicaciones para el staff')->icon('heroicon-o-clipboard-document-list')->color('gray')
            ->visible(fn () => config('waoo.sandbox_staff') === true && class_exists(\App\Services\Staff\ReadStaffJobInstructions::class))
            ->modalHeading('Indicaciones para el staff')
            ->modalDescription('Lo ve cada persona apartada en esta fiesta en su página de la semana. No pongas aquí notas privadas ni dinero. El contacto del día solo aparece el día de la fiesta.')
            ->fillForm(function () use ($e) {
                $r = app(\App\Services\Staff\ReadStaffJobInstructions::class)->execute(\Filament\Facades\Filament::auth()->user(), $e->id);
                $this->instrEventVersion = (int) $r['event_version'];
                $this->instrVersion = (int) $r['version'];
                $this->instrKey = (string) \Illuminate\Support\Str::uuid();
                $i = $r['instructions'];

                return ['parking' => $i['parking'], 'access' => $i['access'], 'bring' => implode("\n", $i['bring'] ?? []),
                    'instructions' => $i['instructions'], 'contact_name' => $i['contact_name'], 'contact_phone' => $i['contact_phone']];
            })
            ->schema([
                \Filament\Forms\Components\TextInput::make('parking')->label('Estacionamiento')->maxLength(1000)->placeholder('Ej.: en la calle, frente a la casa'),
                \Filament\Forms\Components\TextInput::make('access')->label('Acceso')->maxLength(1000)->placeholder('Ej.: entrar por el portón del lado izquierdo'),
                \Filament\Forms\Components\Textarea::make('bring')->label('Qué llevar (una cosa por línea)')->rows(4),
                \Filament\Forms\Components\Textarea::make('instructions')->label('Indicaciones')->rows(3)->maxLength(3000)->placeholder('Ej.: la piñata es a las 4:15; el cumpleañero se llama…'),
                \Filament\Schemas\Components\Grid::make(2)->schema([
                    \Filament\Forms\Components\TextInput::make('contact_name')->label('Contacto del día (nombre)')->maxLength(150),
                    \Filament\Forms\Components\TextInput::make('contact_phone')->label('Teléfono del contacto')->tel()->maxLength(40),
                ]),
            ])
            ->modalSubmitActionLabel('Guardar indicaciones')
            ->action(function (array $data, Action $action) use ($e) {
                $bring = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($data['bring'] ?? ''))), fn ($l) => $l !== ''));
                $blank = fn ($v) => filled($v) ? trim((string) $v) : null;
                try {
                    app(\App\Services\Staff\SaveStaffJobInstructions::class)->execute(\Filament\Facades\Filament::auth()->user(), $e->id, [
                        'expected_event_version' => (int) $this->instrEventVersion, 'expected_version' => (int) $this->instrVersion,
                        'parking' => $blank($data['parking'] ?? null), 'access' => $blank($data['access'] ?? null), 'bring' => $bring,
                        'instructions' => $blank($data['instructions'] ?? null), 'contact_name' => $blank($data['contact_name'] ?? null), 'contact_phone' => $blank($data['contact_phone'] ?? null),
                    ], $this->instrKey ?: (string) \Illuminate\Support\Str::uuid());
                } catch (\Illuminate\Validation\ValidationException $ex) {
                    $this->instrKey = (string) \Illuminate\Support\Str::uuid();
                    \Filament\Notifications\Notification::make()->danger()->title('No se guardó')->body(collect($ex->errors())->flatten()->first())->persistent()->send();
                    $action->halt();
                } catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $ex) {
                    $this->instrKey = (string) \Illuminate\Support\Str::uuid();
                    \Filament\Notifications\Notification::make()->danger()->title('No se guardó')->body($ex->getMessage())->persistent()->send();
                    $action->halt();
                }
                $this->instrKey = '';
                \Filament\Notifications\Notification::make()->success()->title('Indicaciones guardadas')->body('Las ve el staff de esta fiesta al abrir su página.')->send();
            });
    }

    private function contractLabel(\App\Models\Event $e): string
    {
        $link = class_exists(\App\Models\ImportedProjectLink::class) ? \App\Models\ImportedProjectLink::where('event_id', $e->id)->first() : null;
        if ($link) {
            $signed = (bool) (\App\Models\ImportedProjectRevision::find($link->current_revision_id)?->normalized['contract_signed'] ?? false);

            return $signed ? 'Firmado en HoneyBook' : 'Sin firmar en HoneyBook';
        }
        $state = \App\Models\Contract::where('event_id', $e->id)->orderByDesc('revision')->value('state');

        return match ($state) { 'signed' => 'Firmado', 'issued' => 'Enviado para firmar', 'draft' => 'Preparado (sin enviar)', default => 'Sin emitir' };
    }

    public function file(): array
    {
        $e = $this->record->fresh(['customer', 'services.characters', 'reservations']);
        $services = $e->services->map(fn ($s) => (object) [
            'name' => ServiceVariant::whereKey($s->service_variant_id)->value('name'),
            'price' => Labels::money(PriceVersion::whereKey($s->price_version_id)->value('amount_cents')),
            'duration' => Labels::duration($s->duration_minutes),
            // OUTFIT-01: "Mickey (Disneyland)"; the owner note is private and only shown here, never to the client.
            'characters' => $s->characters->map(fn ($c) => $c->quantity.' × '.$c->character_name_snapshot.(filled($c->outfit) ? ' ('.$c->outfit.')' : '').(filled($c->note) ? ' — nota: '.$c->note : ''))->implode(', '),
            'ready' => $s->operational_ready,
        ]);
        $units = Unit::query()->whereIn('id', $e->reservations->pluck('resource_id'))->orderBy('kind')->orderBy('name')->get()
            ->map(fn ($u) => (object) ['name' => $u->name, 'kind' => Labels::label(Labels::KIND, $u->kind)]);
        $history = DB::table('audit_entries')->where(function ($q) use ($e) {
            $q->where(fn ($w) => $w->where('subject_type', 'events')->where('subject_id', $e->id));
            foreach (['financial_revisions', 'ledger_entries', 'zelle_notices', 'zelle_notice_reviews', 'event_cost_snapshots', 'outbound_messages', 'event_message_policies'] as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    $q->orWhere(fn ($w) => $w->where('subject_type', $table)->whereIn('subject_id', DB::table($table)->where('event_id', $e->id)->pluck('id')));
                }
            }
        })->orderByDesc('id')->limit(50)->get()->map(fn ($a) => (object) [
            'what' => self::historyLabel($a), 'who' => User::whereKey($a->actor_id)->value('name') ?? (str_starts_with($a->action, 'portal.') ? 'La clienta' : 'Sistema'),
            'when' => Labels::when(\Carbon\CarbonImmutable::parse($a->created_at)),
        ]);

        return [
            'event' => $e, 'services' => $services, 'units' => $units, 'history' => $history,
            'type' => $e->event_type ? (EventResource::EVENT_TYPES[$e->event_type] ?? $e->event_type) : 'Sin clasificar',
            'status' => Labels::label(Labels::EVENT_STATUS, $e->status), 'status_color' => Labels::EVENT_STATUS_COLOR[$e->status] ?? 'gray',
            'finance' => FinanceGate::enabled() ? app(FinanceSummary::class)->forEvent($e) : null,
            'contract' => $this->contractLabel($e),
            // RESPONSIBLE-SYSTEM C1 (owner decision 2026-10-19): shown only once Codex's column exists; never inferred.
            'responsible' => match ($e->getAttribute('responsible_system')) { 'honeybook' => 'HoneyBook cobra y avisa', 'crm' => 'CRM cobra y avisa', default => null },
        ];
    }
}
