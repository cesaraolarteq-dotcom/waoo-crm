<?php

namespace App\Filament\Resources\Resources;

use App\Filament\Resources\Resources\Pages\ListResourceUnits;
use App\Filament\Support\Labels;
use App\Models\Resource as Unit;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

// Staff, costumes, equipment and vehicles. Each costume or speaker is its own unit (2 Mickey costumes = 2 rows).
class ResourceUnitResource extends Resource
{
    protected static ?string $model = Unit::class;
    protected static ?string $slug = 'staff-y-trajes';
    protected static ?string $modelLabel = 'unidad';
    protected static ?string $pluralModelLabel = 'staff y trajes';
    protected static ?string $navigationLabel = 'Staff y trajes';
    protected static string|\UnitEnum|null $navigationGroup = 'Operación';
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;
    protected static ?string $breadcrumb = 'Staff y trajes';
    protected static bool $hasTitleCaseModelLabel = false;
    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->select('resources.*')
                ->selectSub(fn ($s) => $s->from('characters')->select('name')->whereColumn('characters.id', 'resources.character_id')->limit(1), 'character_name')
                ->selectSub(fn ($s) => $s->from('resource_reservations')->selectRaw('count(*)')->whereColumn('resource_reservations.resource_id', 'resources.id')->where('ends_at', '>', now()), 'upcoming_total'))
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable()->weight('bold')
                    ->description(fn ($record) => $record->code),
                TextColumn::make('kind')->label('Tipo')->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => Labels::label(Labels::KIND, $state)),
                TextColumn::make('detail')->label('Detalle')
                    ->state(fn ($record) => match ($record->kind) {
                        'staff' => collect($record->skills ?? [])->map(fn ($s) => Labels::label(Labels::SKILL, $s))->implode(', ') ?: null,
                        'costume' => $record->character_name,
                        'equipment' => Labels::label(Labels::EQUIPMENT, $record->equipment_kind),
                        'vehicle' => $record->seats ? "{$record->seats} asientos (con conductor)" : null,
                        default => null,
                    })->placeholder('—')->wrap(),
                TextColumn::make('location')->label('Dónde está')->placeholder('—')->toggleable()->visibleFrom('lg'),
                TextColumn::make('upcoming_total')->label('Próximos eventos')->alignEnd()->visibleFrom('md'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn ($state) => Labels::label(Labels::RESOURCE_STATUS, $state))
                    ->color(fn ($state) => Labels::RESOURCE_STATUS_COLOR[$state] ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(Labels::RESOURCE_STATUS),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Todavía no hay staff ni trajes')
            ->emptyStateDescription('Se cargan con el inventario de César: cada persona, cada traje (por ejemplo, 2 de Mickey), cada bocina y cada vehículo.');
    }

    public static function getPages(): array
    {
        return ['index' => ListResourceUnits::route('/')];
    }
}
