<?php

namespace App\Filament\Resources\Resources\Pages;

use App\Filament\Resources\Resources\ResourceUnitResource;
use App\Filament\Support\RecordServicePage;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditResource extends EditRecord
{
    use RecordServicePage;

    protected static string $resource = ResourceUnitResource::class;

    protected static ?string $title = 'Editar unidad';

    // The version the editor was shown; sent as expected_version so a concurrent change returns 409 instead of being overwritten.
    #[Locked]
    public ?int $displayedVersion = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->displayedVersion = $this->getRecord()->version;
    }

    // No delete: the API has no hard-delete endpoints.
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $saved = $this->runRecordService(\App\Services\Records\SaveResource::class, [[...ResourceUnitResource::toServiceInput([...$data, 'kind' => $record->kind]), 'expected_version' => $this->displayedVersion], $record->getKey()]);
        $this->displayedVersion = $saved->version;

        return $saved;
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
