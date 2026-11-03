<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    protected static ?string $title = 'Fiestas';

    protected static ?string $breadcrumb = 'Lista';

    public function getBreadcrumbs(): array
    {
        return []; // already under Agenda → Lista de fiestas
    }

    public function getTabs(): array
    {
        return [
            // Audit 2026-11-02: cancelled parties only under "Todas".
            'upcoming' => Tab::make('Próximas')->modifyQueryUsing(fn ($query) => $query->where('ends_at', '>=', now())->where('status', '!=', 'cancelled')),
            'past' => Tab::make('Pasadas')->modifyQueryUsing(fn ($query) => $query->where('ends_at', '<', now())->where('status', '!=', 'cancelled')->reorder('starts_at', 'desc')),
            'all' => Tab::make('Todas'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn () => static::getResource()::canCreate())->label('Nueva fiesta')];
    }
}
