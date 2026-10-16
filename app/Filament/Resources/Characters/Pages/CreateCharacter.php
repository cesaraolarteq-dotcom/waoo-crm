<?php

namespace App\Filament\Resources\Characters\Pages;

use App\Filament\Resources\Characters\CharacterResource;
use App\Filament\Support\RecordServicePage;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCharacter extends CreateRecord
{
    use RecordServicePage;

    protected static string $resource = CharacterResource::class;

    protected static ?string $title = 'Nuevo personaje';

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runRecordService(\App\Services\Records\SaveCharacter::class, [CharacterResource::toServiceInput($data)]);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
