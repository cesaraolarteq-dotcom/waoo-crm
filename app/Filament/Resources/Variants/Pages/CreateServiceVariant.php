<?php

namespace App\Filament\Resources\Variants\Pages;

use App\Filament\Resources\Variants\ServiceVariantResource;
use App\Filament\Support\RecordServicePage;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateServiceVariant extends CreateRecord
{
    use RecordServicePage;

    protected static string $resource = ServiceVariantResource::class;

    protected static ?string $title = 'Nuevo servicio';

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runRecordService(\App\Services\Records\SaveVariant::class, [ServiceVariantResource::toServiceInput($data)]);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
