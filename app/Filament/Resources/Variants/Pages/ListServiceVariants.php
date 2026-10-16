<?php

namespace App\Filament\Resources\Variants\Pages;

use App\Filament\Resources\Variants\ServiceVariantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceVariants extends ListRecords
{
    protected static string $resource = ServiceVariantResource::class;

    protected static ?string $title = 'Catálogo';

    protected static ?string $breadcrumb = 'Lista';

    protected ?string $subheading = 'Paquetes y servicios con su precio vigente y el personal o equipo que necesitan.';

    // Shown only when the audited service exists (Resource::canCreate()).
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn () => static::getResource()::canCreate())->label('Nuevo servicio')];
    }
}
