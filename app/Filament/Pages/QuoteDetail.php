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

// QUOTE-01 A: one quote, its current revision and history. "Nueva revisión" recaptures the CURRENT event draft with
// the versions this page showed (Locked); a replay keeps the returned historical revision apart from the header.
// Send / PDF / accept / convert are B/C and stay disabled here.
class QuoteDetail extends Page
{
    protected static ?string $slug = 'cotizaciones/{quote}';
    protected static bool $shouldRegisterNavigation = false;
    protected string $view = 'filament.pages.quote-detail';

    #[Locked] public int $quoteId = 0;
    #[Locked] public ?int $displayedQuoteVersion = null;
    #[Locked] public ?int $displayedEventVersion = null;
    #[Locked] public string $reviseKey = '';

    public static function canAccess(): bool
    {
        return parent::canAccess() && QuoteForm::enabled();
    }

    public function mount(int $quote): void
    {
        $this->quoteId = $quote;
        $data = $this->data();
        $this->displayedQuoteVersion = (int) $data['quote']['version'];
        $this->displayedEventVersion = Event::whereKey($data['quote']['event_id'])->value('version');
        $this->reviseKey = (string) Str::uuid();
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
            // B/C are not delivered: one disabled button keeps the page simple on a phone.
            Action::make('soon')->label('Enviar · PDF · Convertir (próximamente)')->disabled()->color('gray'),
            Action::make('event')->label('Ver evento')->color('gray')->url(fn () => \App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $this->data()['quote']['event_id']])),
        ];
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
                $this->reviseKey = (string) Str::uuid();
                $this->displayedQuoteVersion = (int) Quote::whereKey($this->quoteId)->value('version');
                $this->displayedEventVersion = Event::whereKey(Quote::whereKey($this->quoteId)->value('event_id'))->value('version');
            });
    }
}
