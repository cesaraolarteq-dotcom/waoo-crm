<?php

namespace App\Filament\Resources\Characters;

use App\Filament\Resources\Characters\Pages\ListCharacters;
use App\Models\Character;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CharacterResource extends Resource
{
    protected static ?string $model = Character::class;
    protected static ?string $slug = 'personajes';
    protected static ?string $modelLabel = 'personaje';
    protected static ?string $pluralModelLabel = 'personajes';
    protected static ?string $navigationLabel = 'Personajes';
    protected static string|\UnitEnum|null $navigationGroup = 'Operación';
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;
    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->select('characters.*')
                ->selectSub(fn ($s) => $s->from('resources')->selectRaw('count(*)')->whereColumn('resources.character_id', 'characters.id')->where('kind', 'costume')->where('status', 'active'), 'costumes_ready')
                ->selectSub(fn ($s) => $s->from('resources')->selectRaw('count(*)')->whereColumn('resources.character_id', 'characters.id')->where('kind', 'costume'), 'costumes_total'))
            ->columns([
                TextColumn::make('name')->label('Personaje')->searchable()->sortable()->weight('bold'),
                TextColumn::make('costumes')->label('Trajes disponibles')
                    ->state(fn ($record) => "{$record->costumes_ready} de {$record->costumes_total}")
                    ->color(fn ($record) => (int) $record->costumes_ready === 0 ? 'danger' : null),
                TextColumn::make('active')->label('En catálogo')->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Sí' : 'No')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
            ])
            ->filters([TernaryFilter::make('active')->label('En catálogo')->trueLabel('Sí')->falseLabel('No')])
            ->defaultSort('name')
            ->emptyStateHeading('Todavía no hay personajes')
            ->emptyStateDescription('Se cargan con la lista de personajes y cuántos trajes hay de cada uno.');
    }

    public static function getPages(): array
    {
        return ['index' => ListCharacters::route('/')];
    }
}
