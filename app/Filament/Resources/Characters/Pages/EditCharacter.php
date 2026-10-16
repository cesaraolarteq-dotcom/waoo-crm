<?php

namespace App\Filament\Resources\Characters\Pages;

use App\Filament\Resources\Characters\CharacterResource;
use App\Filament\Support\RecordServicePage;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCharacter extends EditRecord
{
    use RecordServicePage;

    protected static string $resource = CharacterResource::class;

    protected static ?string $title = 'Editar personaje';

    // No delete: the API has no hard-delete endpoints.
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $saved = $this->runRecordService(\App\Services\Records\SaveCharacter::class, [CharacterResource::toServiceInput($data), $record->getKey()]);

        return $saved;
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
