<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\RecordServicePage;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEvent extends CreateRecord
{
    use RecordServicePage;

    protected static string $resource = EventResource::class;

    protected static ?string $title = 'Nuevo evento (borrador)';

    protected ?string $subheading = 'Un borrador no aparta staff ni trajes. Se apartan al confirmar con el depósito recibido.';

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runRecordService(\App\Services\Records\SaveEventDraft::class, [EventResource::toServiceInput($data)]);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
