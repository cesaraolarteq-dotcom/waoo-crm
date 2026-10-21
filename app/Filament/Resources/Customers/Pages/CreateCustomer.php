<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\RecordServicePage;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCustomer extends CreateRecord
{
    use RecordServicePage;

    protected static string $resource = CustomerResource::class;

    protected static ?string $title = 'Nuevo cliente';

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runRecordService(\App\Services\Records\SaveCustomer::class, [CustomerResource::toServiceInput($data)]);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
