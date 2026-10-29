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

    public function honeyBookFacts(): ?array {
        if(!config('waoo.sandbox_history'))return null;
        return app(\App\Services\History\ReadHoneyBookEventFacts::class)->execute(\Filament\Facades\Filament::auth()->user(),$this->record->id);
    }

    public const ACTIONS = [
        'event.created' => 'Creó el borrador', 'event.draft_updated' => 'Editó el borrador', 'booking.capacity_reserved' => 'Apartó staff y trajes',
        'finance.revision_created' => 'Fijó los montos', 'payment.zelle_received' => 'Registró un Zelle', 'payment.capacity_linked' => 'Vinculó el pago con los recursos',
        'portal.zelle_notice_submitted' => 'El cliente avisó que envió un Zelle', 'payment.zelle_notice_reviewed' => 'Revisó un aviso de Zelle del cliente',
        'cost.snapshot_recorded' => 'Registró costos',
        'mail.planned' => 'Programó un correo', 'mail.policy_updated' => 'Cambió la pausa de correos',
        'event.cancelled' => 'Canceló la fiesta', 'payment.refund_requested' => 'Pidió un reembolso con tarjeta', 'payment.refunded' => 'Registró un reembolso',
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

    /** STAFF-01 instructions: versions the modal showed (ReadStaffJobInstructions) and one UUID per save intent. */
    #[\Livewire\Attributes\Locked]
    public ?int $instrEventVersion = null;
    #[\Livewire\Attributes\Locked]
    public ?int $instrVersion = null;
    #[\Livewire\Attributes\Locked]
    public string $instrKey = '';

    #[\Livewire\Attributes\Locked]
    public ?int $displayedVersion = null;

    /** Costume hold (Codex QUICK-COSTUME-HOLD V1): versions and characters read when the owner opened the action, one UUID per intent. */
    #[\Livewire\Attributes\Locked]
    public ?array $holdPin = null;

    #[\Livewire\Attributes\Locked]
    public string $holdKey = '';

    #[\Livewire\Attributes\Locked]
    public ?array $holdInput = null;

    public const HOLD_STATE = ['not_attempted' => 'Sin apartar', 'needs_character' => 'Falta elegir el personaje', 'timing_required' => 'Faltan los minutos de montaje',
        'blocked' => 'Ese traje ya está apartado a esa hora', 'held' => 'Traje apartado', 'consumed' => 'Asignado al confirmar', 'released' => 'Liberado',
        'review_required' => 'Revisar el apartado', 'not_required' => 'No lleva traje'];
    public const HOLD_COLOR = ['held' => 'success', 'consumed' => 'success', 'blocked' => 'danger', 'review_required' => 'warning', 'needs_character' => 'warning', 'timing_required' => 'warning'];
    public const HOLD_ISSUES = ['needs_character' => 'Falta elegir el personaje en la reserva.', 'timing_unknown' => 'Se apartó con el horario conocido; faltan minutos de montaje o desmontaje.',
        'costume_unavailable' => 'No hay un traje libre de ese personaje (o de esa versión) a esa hora.', 'costume_catalog_changed' => 'El personaje cambió en el catálogo después de apartarlo.',
        'hold_review_required' => 'La fiesta cambió (hora, paquete o personajes) después de apartar el traje.', 'integrity_invalid' => 'El registro del apartado no se pudo verificar.',
        'hold_unavailable' => 'No se pudo apartar en ese momento; inténtalo de nuevo.'];

    #[\Livewire\Attributes\Locked]
    public string $quoteKey = '';

    /** MAIL-01: client record version and mail pause version shown on this page; changes are refused if they moved. */
    #[\Livewire\Attributes\Locked]
    public ?int $displayedCustomerVersion = null;

    #[\Livewire\Attributes\Locked]
    public ?int $mailPolicyVersion = null;

    #[\Livewire\Attributes\Locked]
    public string $mailKey = '';

    /** Website lead this event was drafted from ("Crear cotización" on Prospectos): marked quoted once the quote exists. */
    #[\Livewire\Attributes\Locked]
    public ?int $quoteLeadId = null;

    #[\Livewire\Attributes\Locked]
    public ?int $quoteLeadVersion = null;

    public function mount(int|string $record): void
    {
        $this->quoteLeadId = request()->integer('lead') ?: null;
        $this->quoteLeadVersion = request()->integer('lead_version') ?: null;
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

    #[\Livewire\Attributes\Locked]
    public array $billingPins=[];
    #[\Livewire\Attributes\Locked]
    public string $billingKey='';

    protected function getHeaderActions(): array
    {
        $e = $this->record;

        // Owner 2026-10-29: most used first; everything else in "Más", least used (and cancel) last.
        $all = collect($this->headerActionList($e))->keyBy(fn (Action $a) => $a->getName());
        $pick = fn (array $names) => collect($names)->map(fn ($n) => $all->get($n))->filter()->values()->all();

        return [
            ...$pick(['confirm', 'money', 'contract', 'edit', 'quoteView']),
            \Filament\Actions\ActionGroup::make($pick(['smsSend', 'staffInstructions', 'mailPlan', 'costs', 'honeybookCopy', 'cutoverEvidence',
                'quoteCreate', 'billingCompany', 'billingPreview', 'refund', 'cancelEvent']))
                ->label('Más')->icon('heroicon-m-chevron-down')->iconPosition(\Filament\Support\Enums\IconPosition::After)->button()->color('gray'),
        ];
    }

    /** @return list<Action> every header action of the event file, in no particular order */
    private function headerActionList(\App\Models\Event $e): array
    {
        return [
            Action::make('billingCompany')->label('Facturar a empresa')->icon('heroicon-o-building-office')->visible(fn()=>config('waoo.sandbox_company',false))
                ->mountUsing(function(?\Filament\Schemas\Schema $schema) use($e) {
                    $current=\App\Models\Event::findOrFail($e->id);
                    $this->billingPins=['expected_event_version'=>$current->version,'expected_billing_version'=>$current->billing_company_version];
                    $this->billingKey=(string)\Illuminate\Support\Str::uuid();
                    $schema?->fill(['enabled'=>$current->billing_company!==null,...($current->billing_company??[])]);
                })
                ->schema([
                    \Filament\Forms\Components\Checkbox::make('enabled')->label('Facturar a empresa')->live(),
                    \Filament\Forms\Components\TextInput::make('name')->label('Nombre de la empresa')->required(fn(\Filament\Schemas\Components\Utilities\Get $get)=>(bool)$get('enabled'))->maxLength(255),
                    \Filament\Forms\Components\TextInput::make('email')->label('Correo de facturación (opcional)')->email()->maxLength(255),
                    \Filament\Forms\Components\Textarea::make('address')->label('Dirección de facturación (opcional)')->maxLength(1000),
                ])
                ->action(function(array $data,Action $action)use($e){
                    try {
                        app(\App\Services\Billing\SaveEventBillingCompany::class)->execute(\Filament\Facades\Filament::auth()->user(),$e->id,
                            [...$this->billingPins,'billing_company'=>!empty($data['enabled'])?array_intersect_key($data,array_flip(['name','email','address'])):null],$this->billingKey);
                    } catch(\App\Exceptions\RecordWriteRejected|\Illuminate\Validation\ValidationException|\Illuminate\Auth\Access\AuthorizationException $ex){
                        \Filament\Notifications\Notification::make()->danger()->title('No se guardó')->body($ex instanceof \Illuminate\Validation\ValidationException?collect($ex->errors())->flatten()->first():$ex->getMessage())->send();$action->halt();return;
                    }
                    $this->record->refresh();\Filament\Notifications\Notification::make()->success()->title('Datos de empresa guardados')->send();
                }),
            Action::make('billingPreview')->label('Factura: vista previa')->icon('heroicon-o-document-magnifying-glass')->visible(fn()=>config('waoo.sandbox_company',false)&&FinanceGate::enabled()&&\App\Models\FinancialRevision::where('event_id',$e->id)->exists())
                ->url(fn()=>url('/admin/api/events/'.$e->id.'/billing-preview.pdf'))->openUrlInNewTab(),
            Action::make('edit')->label('Editar borrador')->color('gray')->visible(fn () => EventResource::canEdit($e))->url(fn () => EventResource::getUrl('edit', ['record' => $e])),
            Action::make('money')->label('Dinero')->icon('heroicon-o-banknotes')->color('gray')->visible(fn () => FinanceGate::enabled())->url(fn () => EventResource::getUrl('money', ['record' => $e])),
            // Owner 2026-10-28: record copy of the HoneyBook contract and invoice (imported parties).
            Action::make('honeybookCopy')->label('Copia de HoneyBook (PDF)')->icon('heroicon-o-document-text')->color('gray')
                ->visible(fn () => \App\Support\HoneyBookCopies::forEvent($e->id) !== null)
                ->url(fn () => url('/admin/api/events/'.$e->id.'/honeybook-copy'))->openUrlInNewTab(),
            // Owner 2026-10-28: text the client through Twilio. Off until the owner turns texting on; only numbers that opted in.
            Action::make('smsSend')->label('Mandar texto')->icon('heroicon-o-chat-bubble-left-ellipsis')->color('gray')
                ->visible(fn () => $e->customer_id !== null)
                ->modalHeading('Mandar texto al cliente')
                ->modalDescription(fn () => self::smsStatusLine($e))
                ->schema([\Filament\Forms\Components\Textarea::make('body')->label('Texto')->required()->maxLength(640)->rows(4)
                    ->helperText('El primer texto a un número lleva al final la línea de HELP/STOP que pide la compañía telefónica.')])
                ->modalSubmitActionLabel('Mandar')
                ->action(function (array $data, Action $action) use ($e) {
                    try {
                        $m = app(\App\Services\Sms\SendSms::class)->execute(\Filament\Facades\Filament::auth()->user(), (int) $e->customer_id, $e->id, (string) $data['body']);
                    } catch (\Illuminate\Validation\ValidationException $err) {
                        \Filament\Notifications\Notification::make()->danger()->title('No se mandó')->body(collect($err->errors())->flatten()->first())->persistent()->send();
                        $action->halt();
                    }
                    $m->status === 'sent'
                        ? \Filament\Notifications\Notification::make()->success()->title('Texto enviado')->send()
                        : \Filament\Notifications\Notification::make()->danger()->title('Twilio no lo aceptó')->body('Código: '.$m->error_code.'. Revisa el número o escríbele por WhatsApp.')->persistent()->send();
                }),
            Action::make('quoteView')->label('Ver cotización')->icon('heroicon-o-document-currency-dollar')->color('gray')
                ->visible(fn () => \App\Filament\Support\QuoteForm::enabled() && \App\Models\Quote::where('event_id', $e->id)->exists())
                ->url(fn () => \App\Filament\Pages\QuoteDetail::getUrl(['quote' => \App\Models\Quote::where('event_id', $e->id)->value('id')])),
            Action::make('quoteCreate')->label('Crear cotización')->icon('heroicon-o-document-currency-dollar')->color('gray')
                // Owner 2026-10-29: a booked party (deposit received or quick booking) never offers a quote.
                ->visible(fn () => \App\Filament\Support\QuoteForm::enabled() && $e->status === 'draft' && ! \App\Models\Quote::where('event_id', $e->id)->exists()
                    && ! \App\Models\QuickBookingRecord::where('event_id', $e->id)->exists() && ! \App\Models\LedgerEntry::where('event_id', $e->id)->where('kind', 'receipt')->exists())
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
                    if ($this->quoteLeadId !== null && $this->quoteLeadVersion !== null) {
                        try {
                            app(\App\Services\Leads\UpdateLead::class)->execute(\Filament\Facades\Filament::auth()->user(), $this->quoteLeadId, ['expected_version' => $this->quoteLeadVersion, 'status' => 'quoted']);
                        } catch (\Throwable) {
                            \Filament\Notifications\Notification::make()->warning()->title('Cotización creada')->body('No se pudo marcar el prospecto como «Cotizado»; cámbialo en Prospectos.')->send();
                        }
                        $this->quoteLeadId = null;
                    }
                    $this->redirect(\App\Filament\Pages\QuoteDetail::getUrl(['quote' => $rev->quote_id]));
                }),
            $this->staffInstructionsAction(),
            $this->cutoverEvidenceAction(),
            Action::make('costs')->label('Costos y margen')->icon('heroicon-o-chart-bar')->color('gray')->visible(fn () => FinanceGate::enabled() && class_exists(\App\Services\Costs\RecordCostSnapshot::class))->url(fn () => EventResource::getUrl('costs', ['record' => $e])),
            Action::make('mailPlan')->label('Programar recordatorios')->icon('heroicon-o-envelope')->color('gray')
                ->visible(fn () => \App\Filament\Support\MailUi::enabled() && $e->status === 'capacity_reserved' && ! \App\Models\ImportedProjectLink::where('event_id', $e->id)->exists())
                ->modalHeading('Programar recordatorios por correo')
                ->modalDescription('Se programan con los datos que ves ahora (evento y ficha del cliente). No se envía nada hasta que César active los correos y apruebe los textos; mientras tanto solo pueden ir a la lista de prueba.')
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
            Action::make('confirm')->label('Apartar recursos y confirmar')->icon('heroicon-o-check-badge')->color('primary')->visible(fn () => ConfirmCapacity::canAccessFor($e))->url(fn () => EventResource::getUrl('confirm', ['record' => $e])),
            $this->cancelEventAction(),
            $this->refundAction(),
        ];
    }

    #[\Livewire\Attributes\Locked]
    public string $changeKey = '';

    public static function changesEnabled(): bool
    {
        return FinanceGate::enabled() && (bool) config('waoo.sandbox_changes', false);
    }

    /** Owner 2026-10-29: cancel keeps the deposit; frees staff, costumes and equipment; pauses reminders. */
    public function cancelEventAction(): Action
    {
        $e = $this->record;
        return Action::make('cancelEvent')->label('Cancelar fiesta')->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn () => self::changesEnabled() && in_array($e->status, ['draft', 'capacity_reserved'], true) && ! \App\Models\ImportedProjectLink::where('event_id', $e->id)->exists())
            ->mountUsing(function (?\Filament\Schemas\Schema $schema) { $this->changeKey = (string) \Illuminate\Support\Str::uuid(); $schema?->fill(); })
            ->modalHeading('Cancelar esta fiesta')
            ->modalDescription(function () use ($e) {
                $m = app(FinanceSummary::class)->forEvent($e);
                return 'Pagado hasta hoy: '.Labels::money($m['net_received_cents'] ?? 0).'. El depósito no es reembolsable y no se devuelve nada automáticamente. '
                    .'Se libera el staff, los trajes y el equipo de esa fecha, se cierran los enlaces de pago abiertos y se pausan los recordatorios. '
                    .'El contrato firmado, los pagos y el historial se conservan. No se le avisa a la clienta: avísale tú por WhatsApp.';
            })
            ->schema([\Filament\Forms\Components\Textarea::make('reason')->label('¿Por qué se cancela?')->required()->minLength(10)->maxLength(1000)->rows(3)])
            ->modalSubmitActionLabel('Sí, cancelar la fiesta')
            ->action(function (array $data, Action $action) use ($e) {
                try {
                    app(\App\Services\Changes\CancelEvent::class)->execute(\Filament\Facades\Filament::auth()->user(), $e->id,
                        ['expected_version' => (int) $this->displayedVersion, 'reason' => (string) $data['reason']], $this->changeKey);
                } catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Validation\ValidationException|\Illuminate\Auth\Access\AuthorizationException|\App\Exceptions\ResponsibilityRejected $ex) {
                    \Filament\Notifications\Notification::make()->danger()->title('No se canceló')->body($ex instanceof \Illuminate\Validation\ValidationException ? collect($ex->errors())->flatten()->first() : $ex->getMessage())->persistent()->send();
                    $action->halt();
                }
                \Filament\Notifications\Notification::make()->success()->title('Fiesta cancelada')->body('Se liberaron los recursos de esa fecha. El dinero recibido se queda registrado.')->send();
                $this->redirect(EventResource::getUrl('view', ['record' => $e]));
            });
    }

    /** Card payments go back through Stripe; Zelle is sent back by the owner from the bank and recorded here. */
    public function refundAction(): Action
    {
        $e = $this->record;
        $receipts = function () use ($e): array {
            $out = [];
            foreach (\App\Models\LedgerEntry::where('event_id', $e->id)->where('environment', 'test')->where('kind', 'receipt')->whereIn('method', ['stripe', 'zelle'])->orderBy('received_at')->get() as $r) {
                $left = $r->amount_cents - (int) \App\Models\RefundRequest::where('receipt_id', $r->id)->whereIn('state', ['requested', 'unknown', 'succeeded'])->sum('amount_cents');
                if ($left > 0) $out[$r->id] = ($r->method === 'stripe' ? 'Tarjeta' : 'Zelle').' · '.Labels::when($r->received_at).' · queda '.Labels::money($left);
            }
            return $out;
        };
        $method = fn ($id) => $id ? \App\Models\LedgerEntry::whereKey((int) $id)->where('event_id', $e->id)->value('method') : null;
        return Action::make('refund')->label('Devolver dinero')->icon('heroicon-o-arrow-uturn-left')->color('gray')
            ->visible(fn () => self::changesEnabled() && $e->responsible_system === 'crm' && $receipts() !== [])
            ->mountUsing(function (?\Filament\Schemas\Schema $schema) { $this->changeKey = (string) \Illuminate\Support\Str::uuid(); $schema?->fill(); })
            ->modalHeading('Devolver dinero')
            ->modalDescription('Según el contrato el depósito no es reembolsable; usa esto solo si tú decides devolver. Con tarjeta, el dinero vuelve a la misma tarjeta por Stripe. Con Zelle, primero mándalo tú desde el banco y luego regístralo aquí.')
            ->schema([
                \Filament\Forms\Components\Select::make('receipt_id')->label('Pago que se devuelve')->options($receipts)->required()->native(false)->live(),
                \Filament\Forms\Components\TextInput::make('amount')->label('Monto a devolver (USD)')->numeric()->minValue(0.01)->step(0.01)->required(),
                \Filament\Forms\Components\Textarea::make('reason')->label('Motivo')->required()->minLength(10)->maxLength(1000)->rows(2),
                \Filament\Forms\Components\Checkbox::make('confirm_sent')->label('Ya mandé el Zelle de vuelta desde el banco')
                    ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $method($get('receipt_id')) === 'zelle')->accepted(fn (\Filament\Schemas\Components\Utilities\Get $get) => $method($get('receipt_id')) === 'zelle'),
                \Filament\Forms\Components\DatePicker::make('sent_on')->label('Fecha en que lo mandaste')->native(false)->maxDate(now())
                    ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $method($get('receipt_id')) === 'zelle')->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => $method($get('receipt_id')) === 'zelle'),
                \Filament\Forms\Components\TextInput::make('reference')->label('Referencia del banco (opcional)')->maxLength(255)
                    ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $method($get('receipt_id')) === 'zelle'),
            ])
            ->modalSubmitActionLabel('Devolver')
            ->action(function (array $data, Action $action) use ($e) {
                $zelle = ! empty($data['confirm_sent']);
                try {
                    $r = app(\App\Services\Changes\RefundPayment::class)->execute(\Filament\Facades\Filament::auth()->user(), $e->id, [
                        'receipt_id' => (int) $data['receipt_id'], 'amount_cents' => (int) round(((float) $data['amount']) * 100), 'reason' => (string) $data['reason'],
                        ...($zelle ? ['confirm_sent' => true, 'sent_on' => \Carbon\CarbonImmutable::parse($data['sent_on'])->format('Y-m-d'), 'reference' => $data['reference'] ?: null] : []),
                    ], $this->changeKey);
                } catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Validation\ValidationException|\Illuminate\Auth\Access\AuthorizationException|\App\Exceptions\ResponsibilityRejected $ex) {
                    // A lost Stripe answer keeps the same key so the retry returns the same refund, never a second one.
                    if (! ($ex instanceof \App\Exceptions\RecordWriteRejected && $ex->status === 503)) $this->changeKey = (string) \Illuminate\Support\Str::uuid();
                    \Filament\Notifications\Notification::make()->danger()->title('No se devolvió')->body($ex instanceof \Illuminate\Validation\ValidationException ? collect($ex->errors())->flatten()->first() : $ex->getMessage())->persistent()->send();
                    $action->halt();
                }
                \Filament\Notifications\Notification::make()->success()->title('Reembolso registrado: '.Labels::money($r->amount_cents))
                    ->body($r->method === 'stripe' ? 'Stripe lo devuelve a la misma tarjeta (tarda de 5 a 10 días en verse).' : 'Quedó registrado el Zelle que mandaste.')->send();
                $this->redirect(EventResource::getUrl('view', ['record' => $e]));
            });
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
                \Filament\Forms\Components\Checkbox::make('owner_attests_signed_original')->label('Este PDF es el contrato original firmado por el cliente')->accepted(),
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

    /** What the assigned staff see on their week page: parking, access, what to bring, notes, contact of the day. */
    /** Read-only costume hold state for a quick booking; null when holds are off or this party has no quick booking. */
    public function costumeHold(): ?array
    {
        if (! class_exists(\App\Services\Booking\ReadQuickBookingCostumeHold::class)) return null;
        try {
            $h = app(\App\Services\Booking\ReadQuickBookingCostumeHold::class)->execute(\Filament\Facades\Filament::auth()->user(), $this->record->id);
        } catch (\Throwable) {
            return null;
        }
        if ($h['state'] === 'not_required') return null;

        return [...$h, 'label' => self::HOLD_STATE[$h['state']] ?? $h['state'], 'color' => self::HOLD_COLOR[$h['state']] ?? 'gray',
            'issue_texts' => array_values(array_unique(array_map(fn ($i) => self::HOLD_ISSUES[$i] ?? 'Requiere revisión.', $h['issues'] ?? []))),
            'units' => collect($h['rows'] ?? [])->map(fn ($r) => (\App\Models\Resource::whereKey($r['resource_id'])->value('name') ?? 'Traje').' · '.$r['character_name'].(filled($r['outfit']) ? ' ('.$r['outfit'].')' : ''))->all()];
    }

    private function pinHold(): void
    {
        $h = app(\App\Services\Booking\ReadQuickBookingCostumeHold::class)->execute(\Filament\Facades\Filament::auth()->user(), $this->record->id);
        $event = \App\Models\Event::findOrFail($this->record->id);
        \App\Exceptions\RecordWriteRejected::unless($event->version === $h['event_version'], 409, 'El evento cambió. Vuelve a revisar el apartado.');
        $summary = [];
        foreach ($h['rows'] as $row) {
            $reservation = \App\Models\ResourceReservation::whereKey($row['reservation_id'])->where('event_id', $event->id)->first();
            \App\Exceptions\RecordWriteRejected::unless($reservation !== null, 409, 'El apartado cambió. Vuelve a revisarlo.');
            $unit = \App\Models\Resource::whereKey($row['resource_id'])->value('name') ?? 'Unidad #'.$row['resource_id'];
            $summary[] = $unit.' · '.$row['character_name'].(filled($row['outfit']) ? ' ('.$row['outfit'].')' : '')
                .' · horario de la fiesta: '.Labels::when($reservation->starts_at).' a '.Labels::when($reservation->ends_at);
        }
        if ($summary === []) {
            foreach (\App\Services\Booking\QuickCostumeHolds::slots($event)['slots'] as $slot) {
                $name = \App\Models\Character::whereKey($slot['character_id'])->value('name') ?? 'Personaje';
                $summary[] = $name.(filled($slot['outfit'] ?? null) ? ' ('.$slot['outfit'].')' : '');
            }
            $summary[] = 'Horario de la fiesta: '.Labels::when($event->starts_at).' a '.Labels::when($event->ends_at);
        }
        if (in_array('timing_unknown', $h['issues'], true)) $summary[] = self::HOLD_ISSUES['timing_unknown'];
        $this->holdPin = ['event_version' => (int) $h['event_version'], 'version' => (int) $h['version'], 'characters' => $h['characters'], 'summary' => implode(' · ', $summary)];
        $this->holdInput = null;
        $this->holdKey = (string) \Illuminate\Support\Str::uuid();
    }

    private function reviewHold(Action $action): void
    {
        try { $this->pinHold(); }
        catch (\Illuminate\Auth\Access\AuthorizationException $error) { throw $error; }
        catch (\Throwable) {
            \Filament\Notifications\Notification::make()->danger()->title('No se pudo comprobar el apartado')->body('Actualiza y revisa antes de continuar. El depósito sigue guardado.')->persistent()->send();
            $action->halt();
        }
    }

    private function holdResult(array $r, string $ok): void
    {
        $label = self::HOLD_STATE[$r['state']] ?? $r['state'];
        $body = implode(' ', array_map(fn ($i) => self::HOLD_ISSUES[$i] ?? '', $r['issues'] ?? []));
        \Filament\Notifications\Notification::make()->{in_array($r['state'], ['held', 'released', 'consumed'], true) && ($r['issues'] ?? []) === [] ? 'success' : 'warning'}()
            ->title($r['state'] === 'held' || $r['state'] === 'released' ? $ok : $label)->body($body ?: null)->send();
        $this->holdPin = null; $this->holdKey = ''; $this->holdInput = null;
    }

    public function holdCostumeAction(): Action
    {
        return Action::make('holdCostume')->label('Apartar traje')->icon('heroicon-o-lock-closed')->color('primary')
            ->requiresConfirmation()->modalHeading('Apartar el traje para esta fiesta')
            ->modalDescription(fn () => ($this->holdPin['summary'] ?? '').' Se intenta apartar este traje; el staff se confirma por separado.')
            ->modalSubmitActionLabel('Apartar')
            ->mountUsing(fn (Action $action) => $this->reviewHold($action))
            ->action(function (Action $action) {
                try {
                    $r = app(\App\Services\Booking\HoldQuickBookingCostumes::class)->execute(\Filament\Facades\Filament::auth()->user(), $this->record->id,
                        ['expected_event_version' => $this->holdPin['event_version'], 'expected_hold_version' => $this->holdPin['version'], 'characters' => $this->holdPin['characters']], $this->holdKey);
                    $this->holdResult($r, 'Traje apartado');
                } catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Validation\ValidationException $e) {
                    \Filament\Notifications\Notification::make()->danger()->title('No se apartó')->body($e->getMessage().' Cierra y vuelve a abrir para revisar.')->send();
                    $action->halt();
                } catch (\Illuminate\Auth\Access\AuthorizationException $e) { throw $e;
                } catch (\Throwable) {
                    \Filament\Notifications\Notification::make()->danger()->title('No se pudo comprobar el apartado')->body('Reintenta la misma solicitud. El depósito sigue guardado.')->persistent()->send();
                    $action->halt();
                }
            });
    }

    public function releaseCostumeAction(): Action
    {
        return Action::make('releaseCostume')->label('Liberar traje')->icon('heroicon-o-lock-open')->color('gray')
            ->modalHeading('Liberar el traje apartado')
            ->modalDescription(fn () => ($this->holdPin['summary'] ?? '').' Se libera este apartado; el depósito sigue registrado y no se cancela la fiesta.')
            ->mountUsing(fn (Action $action) => $this->reviewHold($action))
            ->schema([\Filament\Forms\Components\Textarea::make('reason')->label('¿Por qué lo liberas?')->required()->minLength(10)->maxLength(1000)->rows(2)
                ->readOnly(fn () => $this->holdInput !== null)->placeholder('Ej.: el cliente cambió de personaje')])
            ->modalSubmitActionLabel('Liberar')
            ->action(function (array $data, Action $action) {
                try {
                    $this->holdInput ??= ['expected_event_version' => $this->holdPin['event_version'], 'expected_hold_version' => $this->holdPin['version'], 'reason' => trim($data['reason'])];
                    $r = app(\App\Services\Booking\ReleaseQuickBookingCostumes::class)->execute(\Filament\Facades\Filament::auth()->user(), $this->record->id,
                        $this->holdInput, $this->holdKey);
                    $this->holdResult($r, 'Traje liberado');
                } catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Validation\ValidationException $e) {
                    \Filament\Notifications\Notification::make()->danger()->title('No se liberó')->body($e->getMessage().' Cierra y vuelve a abrir para revisar.')->send();
                    $action->halt();
                } catch (\Illuminate\Auth\Access\AuthorizationException $e) { throw $e;
                } catch (\Throwable) {
                    \Filament\Notifications\Notification::make()->danger()->title('No se pudo comprobar la liberación')->body('Reintenta la misma solicitud. El depósito sigue guardado.')->persistent()->send();
                    $action->halt();
                }
            });
    }

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
            'what' => self::historyLabel($a), 'who' => User::whereKey($a->actor_id)->value('name') ?? (str_starts_with($a->action, 'portal.') ? 'El cliente' : 'Sistema'),
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

    /** One line for the "Mandar texto" window: is texting on, did this number opt in, and the last texts. */
    public static function smsStatusLine(\App\Models\Event $e): string
    {
        $phone = \App\Services\Sms\PhoneNumber::e164(\App\Models\Customer::whereKey($e->customer_id)->value('phone'));
        $consent = $phone ? \App\Models\SmsConsent::where('phone_e164', $phone)->value('status') : null;
        $line = ! \App\Services\Sms\SmsGate::enabled() ? 'Los textos están apagados hasta que tú los actives. ' : '';
        $line .= match (true) {
            ! $phone => 'Este cliente no tiene un celular de EE. UU. válido.',
            $consent === 'opted_in' => 'Este número aceptó recibir textos.',
            $consent === 'opted_out' => 'Este número escribió STOP: no se le puede mandar textos.',
            default => 'Este número no ha aceptado recibir textos: escríbele por WhatsApp.',
        };
        $last = $phone ? \App\Models\SmsMessage::where('phone_e164', $phone)->latest('id')->limit(3)->get() : collect();
        foreach ($last as $m) {
            $line .= ' · '.($m->direction === 'in' ? 'Recibido' : 'Enviado').' '.\App\Filament\Support\Labels::when($m->created_at).' ('.$m->status.')';
        }

        return $line;
    }
}
