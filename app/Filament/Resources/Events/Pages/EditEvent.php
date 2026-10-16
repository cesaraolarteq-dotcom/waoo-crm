<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\RecordServicePage;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditEvent extends EditRecord
{
    use RecordServicePage;

    protected static string $resource = EventResource::class;

    protected static ?string $title = 'Editar borrador';

    protected ?string $subheading = 'Solo se editan borradores. Un evento con recursos apartados se cambia con el flujo de cambios.';

    // The version the editor was shown; sent as expected_version so a concurrent change returns 409 instead of being overwritten.
    #[Locked]
    public ?int $displayedVersion = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->displayedVersion = $this->getRecord()->version;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return EventResource::toFormState($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('money')->label('Dinero')->icon('heroicon-o-banknotes')->color('gray')
                ->visible(fn () => \App\Filament\Support\FinanceGate::enabled())
                ->url(fn () => EventResource::getUrl('money', ['record' => $this->getRecord()])),
            \Filament\Actions\Action::make('confirmCapacity')->label('Apartar recursos y confirmar')->icon('heroicon-o-check-badge')
                ->visible(fn () => ConfirmCapacity::canAccessFor($this->getRecord()))
                ->url(fn () => EventResource::getUrl('confirm', ['record' => $this->getRecord()])),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $saved = $this->runRecordService(\App\Services\Records\SaveEventDraft::class, [[...EventResource::toServiceInput($data), 'expected_version' => $this->displayedVersion], $record->getKey()]);
        $this->displayedVersion = $saved->version;

        return $saved;
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
