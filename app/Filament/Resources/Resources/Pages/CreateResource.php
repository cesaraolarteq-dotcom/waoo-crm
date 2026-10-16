<?php

namespace App\Filament\Resources\Resources\Pages;

use App\Filament\Resources\Resources\ResourceUnitResource;
use App\Filament\Support\RecordServicePage;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateResource extends CreateRecord
{
    use RecordServicePage;

    protected static string $resource = ResourceUnitResource::class;

    protected static ?string $title = 'Nueva unidad';

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runRecordService(\App\Services\Records\SaveResource::class, [ResourceUnitResource::toServiceInput($data)]);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
