<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\{FinanceGate, Labels};
use App\Models\{Event, LedgerEntry};
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

// Client file (read-only): contact, language, her events (past and upcoming) and verified money received.
class ViewCustomer extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CustomerResource::class;

    protected string $view = 'filament.pages.customer-file';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return $this->record->name ?: 'Cliente por completar';
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('edit')->label('Editar datos')->color('gray')->visible(fn () => CustomerResource::canEdit($this->record))
            ->url(fn () => CustomerResource::getUrl('edit', ['record' => $this->record]))];
    }

    #[\Livewire\Attributes\Locked]
    public int $historyPage = 1;

    public function historyPage(int $page): void { $this->historyPage = max(1, $page); }

    #[\Livewire\Attributes\Locked]
    public array $relinkPins = [];
    #[\Livewire\Attributes\Locked]
    public array $relinkCustomers = [];
    #[\Livewire\Attributes\Locked]
    public string $relinkKey = '';
    public string $relinkMode = 'existing';
    public string $relinkCustomerId = '';
    public string $relinkName = '';
    public string $relinkEmail = '';
    public string $relinkPhone = '';
    public string $relinkReason = '';

    public function startHistoryRelink(int $historyId): void
    {
        \App\Services\History\HistoryAccess::actor(\Filament\Facades\Filament::auth()->user());
        \App\Services\Finance\Sandbox::require('sandbox_history_verification');
        $history = \App\Models\HistoricalEvent::where('customer_id',$this->record->id)->findOrFail($historyId);
        $revision = \App\Models\HistoricalEventRevision::findOrFail($history->current_revision_id);
        \App\Services\History\HistoricalIntegrity::revision($history,$revision);
        $this->relinkPins=['id'=>$history->id,'expected_history_version'=>$history->version,'expected_revision_id'=>$revision->id,'expected_source_sha256'=>$revision->source_sha256];
        $customers=\App\Models\Customer::orderBy('id')->limit(10001)->get();
        abort_if($customers->count()>10000,409);
        $this->relinkCustomers=$customers->mapWithKeys(fn($c)=>[$c->id=>['name'=>$c->name?:'Cliente por completar','version'=>$c->version]])->all();
        $this->relinkKey=(string)\Illuminate\Support\Str::uuid();
        $this->relinkMode='existing';$this->relinkCustomerId=$this->relinkName=$this->relinkEmail=$this->relinkPhone=$this->relinkReason='';
    }

    public function saveHistoryRelink(): void
    {
        if(!$this->relinkPins)abort(409);
        $pins=$this->relinkPins;unset($pins['id']);
        $target=$this->relinkMode==='new'
            ? ['mode'=>'new','name'=>$this->relinkName,'email'=>$this->relinkEmail?:null,'phone'=>$this->relinkPhone?:null,'locale'=>'es']
            : ['mode'=>'existing','id'=>$this->relinkCustomerId,'expected_version'=>$this->relinkCustomers[$this->relinkCustomerId]['version']??0];
        try {
            $result=app(\App\Services\History\ReassignHistoricalCustomer::class)->execute(\Filament\Facades\Filament::auth()->user(),$this->relinkPins['id'],
                [...$pins,'owner_reason'=>$this->relinkReason,'target'=>$target],$this->relinkKey);
        } catch (\Illuminate\Validation\ValidationException|\App\Exceptions\RecordWriteRejected $e) {
            \Filament\Notifications\Notification::make()->danger()->title('No se corrigió')->body($e instanceof \Illuminate\Validation\ValidationException?collect($e->errors())->flatten()->first():$e->getMessage())->send();return;
        }
        $this->relinkPins=[];
        \Filament\Notifications\Notification::make()->success()->title('Vínculo corregido; evidencia anterior conservada')->send();
        $this->redirect(CustomerResource::getUrl('view',['record'=>$result['customer_id']]));
    }

    public function honeyBookSources(): ?array {
        if(!config('waoo.sandbox_history'))return null;
        return app(\App\Services\History\ReadHoneyBookCustomerSources::class)->execute(\Filament\Facades\Filament::auth()->user(),$this->record->id);
    }

    public function honeyBookHistory(): ?array
    {
        if (!config('waoo.sandbox_history')) return null;
        return app(\App\Services\History\ReadCustomerHistory::class)->execute(
            \Filament\Facades\Filament::auth()->user(), $this->record->id, ['page'=>$this->historyPage]);
    }

    public function file(): array
    {
        $events = Event::query()->where('customer_id', $this->record->getKey())->orderByDesc('starts_at')->get();
        $paid = FinanceGate::enabled()
            ? (int) LedgerEntry::whereIn('event_id', $events->pluck('id'))->where('environment', 'test')->where('kind', 'receipt')->sum('amount_cents')
              - (int) LedgerEntry::whereIn('event_id', $events->pluck('id'))->where('environment', 'test')->where('kind', 'refund')->sum('amount_cents')
            : null;
        $map = fn ($e) => (object) ['title' => $e->title, 'when' => Labels::when($e->starts_at), 'type' => $e->event_type ? (EventResource::EVENT_TYPES[$e->event_type] ?? '') : '',
            'status' => Labels::label(Labels::EVENT_STATUS, $e->status), 'color' => Labels::EVENT_STATUS_COLOR[$e->status] ?? 'gray',
            'url' => EventResource::getUrl('view', ['record' => $e])];

        return [
            'c' => $this->record,
            'upcoming' => $events->filter(fn ($e) => $e->ends_at->isFuture())->sortBy('starts_at')->map($map)->values(),
            'past' => $events->filter(fn ($e) => ! $e->ends_at->isFuture())->map($map)->values(),
            'paid' => $paid,
            'since' => $this->record->created_at ? $this->record->created_at->setTimezone(Labels::TZ)->locale('es')->translatedFormat('j M Y') : null,
        ];
    }
}
