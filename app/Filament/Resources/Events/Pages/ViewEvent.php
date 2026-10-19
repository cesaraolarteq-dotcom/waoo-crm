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
        } catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Validation\ValidationException|\Illuminate\Auth\Access\AuthorizationException $e) {
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
                    } catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Auth\Access\AuthorizationException $ex) {
                        \Filament\Notifications\Notification::make()->danger()->title('No se creó')->body($ex->getMessage().' Recarga la página.')->persistent()->send();
                        $action->halt();
                    }
                    $this->quoteKey = (string) \Illuminate\Support\Str::uuid();
                    $this->redirect(\App\Filament\Pages\QuoteDetail::getUrl(['quote' => $rev->quote_id]));
                }),
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
                    } catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Validation\ValidationException|\Illuminate\Auth\Access\AuthorizationException $ex) {
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
