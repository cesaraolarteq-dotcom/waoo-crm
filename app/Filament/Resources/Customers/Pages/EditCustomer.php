<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\RecordServicePage;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCustomer extends EditRecord
{
    use RecordServicePage;

    protected static string $resource = CustomerResource::class;

    protected static ?string $title = 'Editar clienta';

    // No delete: the API has no hard-delete endpoints.
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $saved = $this->runRecordService(\App\Services\Records\SaveCustomer::class, [CustomerResource::toServiceInput($data), $record->getKey()]);

        return $saved;
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
