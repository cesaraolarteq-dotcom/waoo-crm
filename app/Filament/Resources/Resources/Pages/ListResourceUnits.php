<?php

namespace App\Filament\Resources\Resources\Pages;

use App\Filament\Resources\Resources\ResourceUnitResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListResourceUnits extends ListRecords
{
    protected static string $resource = ResourceUnitResource::class;

    protected static ?string $title = 'Staff y trajes';

    protected static ?string $breadcrumb = 'Lista';

    protected ?string $subheading = 'Personas, trajes, equipo y vehículos. Cada traje o bocina cuenta por separado.';

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('Todo')];
        foreach (['staff' => 'Personas', 'costume' => 'Trajes', 'equipment' => 'Equipo', 'vehicle' => 'Vehículos'] as $kind => $label) {
            $tabs[$kind] = Tab::make($label)->modifyQueryUsing(fn ($query) => $query->where('kind', $kind));
        }

        return $tabs;
    }
}
