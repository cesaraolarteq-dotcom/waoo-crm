<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected static ?string $title = 'Clientes';

    protected static ?string $breadcrumb = 'Lista';

    protected ?string $subheading = 'Todos los clientes, con su idioma y cuántos eventos tienen.';

    // Shown only when the audited service exists (Resource::canCreate()).
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn () => static::getResource()::canCreate())->label('Nuevo cliente')];
    }
}
