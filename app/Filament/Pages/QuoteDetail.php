<?php

namespace App\Filament\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Support\{Labels, QuoteForm};
use App\Models\{Event, Quote};
use App\Services\Quotes\{ReadQuotes, ReviseQuote};
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

// QUOTE-01: one quote, its current revision and history. "Nueva revisión" recaptures the CURRENT event draft with
// the versions this page showed (Locked); a replay keeps the returned historical revision apart from the header.
// B/C owner side over Codex's services: prepare the client link (SendQuote: frozen PDF + consent, link recoverable while active,
// re-copy returns the same active link), view the exact PDF, revoke the link, see her decision, and convert an
// accepted quote into the agreed money (ConvertAcceptedQuote). Nothing is sent; accepting never charges or books.
class QuoteDetail extends Page
{
    protected static ?string $slug = 'cotizaciones/{quote}';
    protected static bool $shouldRegisterNavigation = false;
    protected string $view = 'filament.pages.quote-detail';

    #[Locked] public int $quoteId = 0;
    #[Locked] public ?int $displayedQuoteVersion = null;
    #[Locked] public ?int $displayedEventVersion = null;
    #[Locked] public ?int $displayedRevision = null;
    #[Locked] public ?int $displayedGrantId = null;
    #[Locked] public string $reviseKey = '';
    #[Locked] public string $sendKey = '';
    #[Locked] public string $convertKey = '';

    /** Link panel shown right after preparing/copying (never stored beyond this page view). */
    public ?array $newLink = null;

    public static function canAccess(): bool
    {
        return parent::canAccess() && QuoteForm::enabled();
    }

    public function mount(int $quote): void
    {
        $this->quoteId = $quote;
        $data = $this->data();
        $this->displayedQuoteVersion = (int) $data['quote']['version'];
        $this->displayedRevision = (int) $data['quote']['current_revision'];
        $this->displayedGrantId = $this->clientSide()['grant']?->id;
        $this->displayedEventVersion = Event::whereKey($data['quote']['event_id'])->value('version');
        $this->reviseKey = (string) Str::uuid();
        $this->sendKey = (string) Str::uuid();
        $this->convertKey = (string) Str::uuid();
    }

    /** Current revision's client link, presentation and decision (owner view; never the token itself). */
    public function clientSide(): array
    {
        $d = $this->data();
        $rev = \App\Models\QuoteRevision::where('quote_id', $this->quoteId)->where('revision', $d['quote']['current_revision'])->first();
        $grant = $rev ? \App\Models\PortalGrant::where('quote_revision_id', $rev->id)->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('id')->first() : null;
        $presentation = $rev ? \App\Models\QuotePresentation::where('quote_revision_id', $rev->id)->first() : null;
        $decision = $rev ? \App\Models\QuoteDecision::where('quote_revision_id', $rev->id)->first() : null;

        return [
            'revision_id' => $rev?->id, 'presentation' => $presentation, 'grant' => $grant,
            'grant_expires' => $grant ? Labels::when($grant->expires_at) : null,
            'opened' => $grant && \Illuminate\Support\Facades\DB::table('quote_pdf_views')->where('portal_grant_id', $grant->id)->exists(),
            'decision' => $decision, 'conversion' => $d['conversion'],
            'can_send' => in_array($d['quote']['effective_status'], ['draft', 'sent'], true) && $rev !== null,
        ];
    }

    public function data(): array
    {
        return app(ReadQuotes::class)->show(Filament::auth()->user(), $this->quoteId);
    }

    public function getTitle(): string
    {
        return 'Cotización '.(Quote::whereKey($this->quoteId)->value('number') ?? '');
    }

    public function money(?int $c): string
    {
        return Labels::money($c);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->reviseAction(),
            $this->sendAction(),
            $this->convertAction(),
            $this->pdfAction(),
            Action::make('event')->label('Ver evento')->color('gray')->url(fn () => \App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $this->data()['quote']['event_id']])),
        ];
    }

    /** Prepare (first time) or copy again (same active link) — one explicit click, never automatic. */
    public function sendAction(): Action
    {
        return Action::make('send')->icon('heroicon-o-link')->color('primary')
            ->label(fn () => $this->displayedGrantId !== null ? 'Copiar enlace para la clienta' : 'Preparar enlace para la clienta')
            ->visible(fn () => QuoteForm::enabled() && $this->clientSide()['can_send'])
            ->modalHeading(fn () => $this->displayedGrantId !== null ? 'Copiar el enlace activo' : 'Preparar el enlace de la cotización')
            ->modalDescription(fn () => $this->displayedGrantId !== null
                ? 'Se muestra el mismo enlace que ya tiene la clienta. No se crea uno nuevo.'
                : 'Se congela esta versión (PDF y texto de aceptación) y se crea un enlace privado. Nada se envía: tú lo mandas por WhatsApp o SMS.')
            ->modalSubmitActionLabel('Mostrar enlace')
            ->action(function (Action $action) {
                try {
                    if ($this->displayedGrantId !== null) {
                        $link = app(\App\Services\Portal\RevealPortalGrantLink::class)->execute(Filament::auth()->user(),
                            (int) $this->data()['quote']['event_id'], $this->displayedGrantId);
                        $r = ['url' => $link->url, 'expires_at' => $link->expires_at];
                    } else {
                        $r = app(\App\Services\Quotes\SendQuote::class)->execute(Filament::auth()->user(), $this->quoteId,
                            ['expected_version' => (int) $this->displayedQuoteVersion, 'expected_revision' => (int) $this->displayedRevision], $this->sendKey);
                        $this->displayedQuoteVersion = (int) $r['quote']->version;
                        $this->displayedGrantId = (int) $r['portal_grant_id'];
                    }
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('No se preparó')->body(collect($e->errors())->flatten()->first())->persistent()->send();
                    $action->halt();
                } catch (RecordWriteRejected|AuthorizationException|\App\Exceptions\PortalLinkRejected $e) {
                    $this->newLink = null;
                    $this->sendKey = (string) Str::uuid();
                    Notification::make()->danger()->title('No se preparó')->body($e->getMessage().' Recarga la página para ver lo último.')->persistent()->send();
                    $action->halt();
                }
                $this->sendKey = (string) Str::uuid();
                $this->newLink = $this->linkPanel($r['url'], $r['expires_at']);
            });
    }

    private function linkPanel(string $url, string $expiresIso): array
    {
        $q = Quote::findOrFail($this->quoteId);
        $customer = \App\Models\Customer::find($q->customer_id);
        $first = trim(explode(' ', (string) $customer?->name)[0] ?? '');
        $until = rtrim(Labels::when(\Carbon\CarbonImmutable::parse($expiresIso)), '.');

        return ['url' => $url, 'expires' => $until, 'phone' => $customer?->phone, 'locale' => $q->locale, 'heading' => 'Enlace de la cotización',
            'hint' => 'Puedes volver a copiarlo aquí mientras esté activo.',
            'msg_es' => "¡Hola {$first}! Aquí está tu cotización de Waoo Party: {$url} (vence el {$until}). Revísala y, si todo está bien, acéptala ahí mismo. ¡Estamos emocionados de ser parte de tu celebración!",
            'msg_en' => "Hi {$first}! Here is your Waoo Party quote: {$url} (expires {$until}). Take a look and, if everything looks good, accept it right there. We're excited to be part of your celebration!"];
    }

    public function hideLink(): void
    {
        $this->newLink = null;
    }

    /** Exact frozen PDF the client sees (owner copy), never regenerated from the current catalog. */
    public function pdfAction(): Action
    {
        return Action::make('pdf')->label('Ver PDF')->icon('heroicon-o-document-arrow-down')->color('gray')
            ->visible(fn () => QuoteForm::enabled() && $this->clientSide()['presentation'] !== null)
            ->action(function () {
                $p = $this->clientSide()['presentation'];
                $bytes = app(\App\Services\Quotes\QuoteArtifacts::class)->read($p);
                $name = 'cotizacion-'.Quote::whereKey($this->quoteId)->value('number').'.pdf';

                return response()->streamDownload(fn () => print($bytes), $name, ['Content-Type' => 'application/pdf']);
            });
    }

    /** The client can no longer open it; a new link can be prepared afterwards. */
    public function revokeLinkAction(): Action
    {
        return Action::make('revokeLink')->label('Anular enlace')->color('danger')->size('sm')->link()
            ->requiresConfirmation()->modalHeading('Anular el enlace de la cotización')
            ->modalDescription('La clienta ya no podrá abrirla con ese enlace. Si ya decidió, su respuesta se conserva. Puedes preparar uno nuevo después.')
            ->modalSubmitActionLabel('Anular')
            ->action(function () {
                if ($this->displayedGrantId === null) return;
                app(\App\Services\Portal\RevokePortalGrant::class)->execute(Filament::auth()->user(), $this->displayedGrantId);
                $this->displayedGrantId = null;
                $this->sendKey = (string) Str::uuid();
                $this->newLink = null;
                Notification::make()->success()->title('Enlace anulado')->send();
            });
    }

    /** Accepted → agreed money (financial revision) with the displayed versions; then deposit/contract as usual. */
    public function convertAction(): Action
    {
        return Action::make('convert')->label('Pasar a montos acordados')->icon('heroicon-o-banknotes')->color('success')
            ->visible(fn () => QuoteForm::enabled() && $this->data()['quote']['effective_status'] === 'accepted' && ! $this->clientSide()['conversion'] && $this->clientSide()['decision']?->outcome === 'accepted')
            ->requiresConfirmation()->modalHeading('Pasar la cotización aceptada a montos acordados')
            ->modalDescription(function () {
                $d = $this->data();
                $total = collect($d['revisions'])->firstWhere('revision', $d['quote']['current_revision'])['total_cents'] ?? null;

                return 'Se fijan los montos de esta cotización (total '.$this->money($total).', con su descuento) para cobrar el depósito. No cobra nada, no aparta staff ni crea contrato todavía.';
            })
            ->modalSubmitActionLabel('Fijar montos')
            ->action(function (Action $action) {
                $side = $this->clientSide();
                try {
                    app(\App\Services\Quotes\ConvertAcceptedQuote::class)->execute(Filament::auth()->user(), $this->quoteId, [
                        'expected_version' => (int) $this->displayedQuoteVersion, 'expected_revision' => (int) $this->displayedRevision,
                        'expected_event_version' => (int) $this->displayedEventVersion, 'decision_id' => (int) $side['decision']->id], $this->convertKey);
                } catch (ValidationException|RecordWriteRejected|AuthorizationException $e) {
                    $this->convertKey = (string) Str::uuid();
                    $msg = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
                    Notification::make()->danger()->title('No se fijaron los montos')->body($msg.' Recarga la página para ver lo último.')->persistent()->send();
                    $action->halt();
                }
                $this->convertKey = (string) Str::uuid();
                $this->displayedQuoteVersion = (int) Quote::whereKey($this->quoteId)->value('version');
                Notification::make()->success()->title('Montos acordados fijados')->body('Siguiente: registra el depósito en Dinero.')
                    ->actions([\Filament\Actions\Action::make('money')->label('Ir a Dinero')->url(\App\Filament\Resources\Events\EventResource::getUrl('money', ['record' => $this->data()['quote']['event_id']]))])->send();
            });
    }

    public function reviseAction(): Action
    {
        return Action::make('revise')->label('Nueva revisión')->icon('heroicon-o-arrow-path')
            ->visible(fn () => $this->data()['quote']['effective_status'] === 'draft')
            ->modalHeading('Nueva revisión de la cotización')
            ->modalDescription(fn () => 'Se toma el borrador del evento tal como está ahora (versión '.$this->displayedEventVersion.'). Si cambiaste precios, paquete o transporte, primero guárdalos en el evento. La versión anterior queda en el historial.')
            ->fillForm(function () {
                $d = $this->data(); $cur = collect($d['revisions'])->firstWhere('revision', $d['quote']['current_revision']);

                return ['locale' => $d['quote']['locale'], 'owner_note' => $d['quote']['owner_note'] ?? null, 'discounts' => QuoteForm::formDiscounts($cur['discounts_snapshot'] ?? [])];
            })
            ->schema(QuoteForm::schema())
            ->modalSubmitActionLabel('Guardar revisión')
            ->action(function (array $data, Action $action) {
                try {
                    $rev = app(ReviseQuote::class)->execute(Filament::auth()->user(), $this->quoteId,
                        ['expected_version' => (int) $this->displayedQuoteVersion, 'expected_event_version' => (int) $this->displayedEventVersion, ...QuoteForm::input($data)], $this->reviseKey);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('No se guardó')->body(collect($e->errors())->flatten()->first())->persistent()->send();
                    $action->halt();
                } catch (RecordWriteRejected|AuthorizationException $e) {
                    Notification::make()->danger()->title('No se guardó')->body($e->getMessage().' Recarga la página para ver lo último.')->persistent()->send();
                    $action->halt();
                }
                Notification::make()->success()->title('Revisión '.$rev->revision.' guardada')->send();
                $this->displayedRevision = $rev->revision;
                $this->displayedGrantId = null;
                $this->newLink = null;
                $this->reviseKey = (string) Str::uuid();
                $this->displayedQuoteVersion = (int) Quote::whereKey($this->quoteId)->value('version');
                $this->displayedEventVersion = Event::whereKey(Quote::whereKey($this->quoteId)->value('event_id'))->value('version');
            });
    }
}
