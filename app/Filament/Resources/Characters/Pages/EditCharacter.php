<?php

namespace App\Filament\Resources\Characters\Pages;

use App\Filament\Resources\Characters\CharacterResource;
use App\Filament\Support\RecordServicePage;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditCharacter extends EditRecord
{
    use RecordServicePage;

    protected static string $resource = CharacterResource::class;

    protected static ?string $title = 'Editar personaje';

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
        $saved = $this->runRecordService(\App\Services\Records\SaveCharacter::class, [[...CharacterResource::toServiceInput($data), 'expected_version' => $this->displayedVersion], $record->getKey()]);

        $this->displayedVersion = $saved->version;

        return $saved;
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
