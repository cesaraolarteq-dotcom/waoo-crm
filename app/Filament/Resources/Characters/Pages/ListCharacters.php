<?php

namespace App\Filament\Resources\Characters\Pages;

use App\Filament\Resources\Characters\CharacterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCharacters extends ListRecords
{
    protected static string $resource = CharacterResource::class;

    protected static ?string $title = 'Personajes';

    protected static ?string $breadcrumb = 'Lista';

    protected ?string $subheading = 'Cada personaje y cuántos de sus trajes están listos para usar.';

    // Shown only when the audited service exists (Resource::canCreate()).
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn () => static::getResource()::canCreate())->label('Nuevo personaje')];
    }
}
