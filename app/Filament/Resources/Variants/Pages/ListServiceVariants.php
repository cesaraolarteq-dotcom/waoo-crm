<?php

namespace App\Filament\Resources\Variants\Pages;

use App\Filament\Resources\Variants\ServiceVariantResource;
use Filament\Resources\Pages\ListRecords;

class ListServiceVariants extends ListRecords
{
    protected static string $resource = ServiceVariantResource::class;

    protected ?string $subheading = 'Paquetes y servicios con su precio vigente y el personal o equipo que necesitan.';
}
