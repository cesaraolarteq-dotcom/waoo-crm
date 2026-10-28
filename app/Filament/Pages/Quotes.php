<?php

namespace App\Filament\Pages;

use App\Filament\Support\{Labels, QuoteForm};
use App\Services\Quotes\ReadQuotes;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

// QUOTE-01 A: owner list of quotes (paginated by the service, filter resets the page).
class Quotes extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;
    protected static string|\UnitEnum|null $navigationGroup = 'Vender';
    protected static ?string $navigationLabel = 'Cotizaciones';
    protected static ?int $navigationSort = 2;
    protected static ?string $slug = 'cotizaciones';
    protected static ?string $title = 'Cotizaciones';
    protected string $view = 'filament.pages.quotes';

    #[Url]
    public string $status = 'all';

    #[Url(as: 'pagina')]
    public int $quotePage = 1;

    public array $pageInfo = [];

    /** Owner 2026-10-28: start a quote from here too (new draft → the quote modal opens on save). */
    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('newQuote')->label('Nueva cotización')->icon('heroicon-o-plus')
                ->visible(fn () => \App\Filament\Resources\Events\EventResource::canCreate())
                ->url(fn () => \App\Filament\Resources\Events\EventResource::getUrl('create', ['cotizar' => 1])),
            \Filament\Actions\Action::make('fromLead')->label('Desde un prospecto')->color('gray')
                ->visible(fn () => (bool) config('waoo.sandbox_lead_intake', false))
                ->url(fn () => Leads::getUrl()),
        ];
    }

    public static function canAccess(): bool
    {
        return parent::canAccess() && QuoteForm::enabled();
    }

    public function updatedStatus(): void
    {
        $this->quotePage = 1;
    }

    public function goToPage(int $page): void
    {
        $this->quotePage = max(1, min($page, $this->pageInfo['last'] ?? $page));
    }

    public function rows(): array
    {
        $page = app(ReadQuotes::class)->index(Filament::auth()->user(), [...(array_key_exists($this->status, QuoteForm::STATUS) ? ['status' => $this->status] : []),
            'per_page' => 25, 'page' => max(1, $this->quotePage)]);
        $this->pageInfo = ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()];
        $items = collect($page->items());
        $revs = \App\Models\QuoteRevision::query()->where(function ($w) use ($items) {
            foreach ($items as $q) $w->orWhere(fn ($x) => $x->where('quote_id', $q['id'])->where('revision', $q['current_revision']));
        })->get()->keyBy('quote_id');

        return $items->map(function ($q) use ($revs) {
            $rev = $revs[$q['id']] ?? null;

            return (object) ['id' => $q['id'], 'number' => $q['number'], 'status' => $q['effective_status'], 'revision' => $q['current_revision'],
                'client' => $rev?->customer_snapshot['name'] ?? '', 'when' => $rev ? Labels::when(\Carbon\CarbonImmutable::parse($rev->event_snapshot['starts_at'])) : '',
                'total' => $rev ? Labels::money($rev->total_cents) : '—', 'valid' => Labels::when(\Carbon\CarbonImmutable::parse($q['valid_until']))];
        })->all();
    }
}
