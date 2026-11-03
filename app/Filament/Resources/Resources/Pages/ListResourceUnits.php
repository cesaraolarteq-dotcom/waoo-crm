<?php

namespace App\Filament\Resources\Resources\Pages;

use App\Filament\Resources\Resources\ResourceUnitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListResourceUnits extends ListRecords
{
    protected static string $resource = ResourceUnitResource::class;

    protected static ?string $title = 'Staff y trajes';

    protected static ?string $breadcrumb = 'Lista';

    public function getTabs(): array
    {
        // Audit 2026-11-02: opens on the people; costumes and equipment one tap away; "Todo" last.
        $tabs = [];
        foreach (['staff' => 'Personas', 'costume' => 'Trajes', 'equipment' => 'Equipo', 'vehicle' => 'Vehículos'] as $kind => $label) {
            $tabs[$kind] = Tab::make($label)->modifyQueryUsing(fn ($query) => $query->where('kind', $kind));
        }
        $tabs['all'] = Tab::make('Todo');

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'staff';
    }

    // Shown only when the audited service exists (Resource::canCreate()).
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn () => static::getResource()::canCreate())->label('Nueva unidad')];
    }
}
