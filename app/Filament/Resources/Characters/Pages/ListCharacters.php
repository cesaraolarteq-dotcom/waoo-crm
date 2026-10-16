<?php

namespace App\Filament\Resources\Characters\Pages;

use App\Filament\Resources\Characters\CharacterResource;
use Filament\Resources\Pages\ListRecords;

class ListCharacters extends ListRecords
{
    protected static string $resource = CharacterResource::class;

    protected static ?string $title = 'Personajes';

    protected static ?string $breadcrumb = 'Lista';

    protected ?string $subheading = 'Cada personaje y cuántos de sus trajes están listos para usar.';
}
