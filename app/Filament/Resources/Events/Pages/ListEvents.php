<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    protected static ?string $title = 'Eventos';

    protected static ?string $breadcrumb = 'Lista';

    protected ?string $subheading = 'Horas en hora de Houston. "Recursos apartados" significa staff y trajes asignados con depósito verificado; no es contrato firmado.';

    public function getTabs(): array
    {
        return [
            'upcoming' => Tab::make('Próximos')->modifyQueryUsing(fn ($query) => $query->where('ends_at', '>=', now())),
            'past' => Tab::make('Pasados')->modifyQueryUsing(fn ($query) => $query->where('ends_at', '<', now())->reorder('starts_at', 'desc')),
            'all' => Tab::make('Todos'),
        ];
    }
}
