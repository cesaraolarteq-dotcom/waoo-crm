<?php

namespace App\Filament\Resources\Resources;

use App\Filament\Resources\Resources\Pages\ListResourceUnits;
use App\Filament\Support\Labels;
use App\Models\Resource as Unit;
use App\Filament\Resources\Resources\Pages\{CreateResource, EditResource};
use App\Filament\Support\RecordServicePageGate;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\{CheckboxList, Select, TextInput};
use Filament\Schemas\Components\Utilities\Get;

use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\{Filter, SelectFilter};
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

    // Writable only once Codex's audited service exists (docs/RECORD-SERVICES.md); until then the list is read-only.
    public static function canCreate(): bool
    {
        return RecordServicePageGate::ready(\App\Services\Records\SaveResource::class);
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::canCreate();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('kind')->label('Tipo')->options(Labels::KIND)->required()->native(false)->live()
                ->disabledOn('edit')->helperText('El tipo no se cambia después; si te equivocaste, crea una ficha nueva.'),
            Select::make('status')->label('Estado')->options(Labels::RESOURCE_STATUS)->default('active')->required()->native(false),
            TextInput::make('name')->label('Nombre')->required()->maxLength(255)->helperText('Ej.: "Mickey #2" o el nombre de la persona.'),
            TextInput::make('code')->label('Código interno')->required()->maxLength(60)->helperText('Corto y único, ej.: TRJ-MICKEY-2.'),
            CheckboxList::make('skills')->label('Qué puede hacer')->options(Labels::SKILL)->columns(3)->columnSpanFull()
                ->visible(fn (Get $get) => $get('kind') === 'staff')->helperText('Una persona puede tener varias, pero en un mismo evento hace solo un rol.'),
            Select::make('character_id')->label('Personaje del traje')->native(false)->searchable()
                ->options(fn () => \App\Models\Character::query()->orderBy('name')->pluck('name', 'id'))
                ->visible(fn (Get $get) => $get('kind') === 'costume')->required(fn (Get $get) => $get('kind') === 'costume')->live(),
            Select::make('outfit')->label('Versión de este traje')->native(false)->placeholder('Sin versión específica')
                ->options(fn (Get $get) => collect(\App\Models\Character::find($get('character_id'))?->outfits ?? [])->mapWithKeys(fn ($o) => [$o => $o])->all())
                ->visible(fn (Get $get) => $get('kind') === 'costume' && filled(\App\Models\Character::find($get('character_id'))?->outfits))
                ->helperText('Un traje sin versión no sirve cuando el cliente pide una versión exacta: para eso el traje debe tener esa versión.'),
            Select::make('equipment_kind')->label('Tipo de equipo')->options(Labels::EQUIPMENT)->native(false)
                ->visible(fn (Get $get) => $get('kind') === 'equipment')->required(fn (Get $get) => $get('kind') === 'equipment'),
            TextInput::make('seats')->label('Asientos (contando al conductor)')->integer()->minValue(1)->maxValue(100)
                ->visible(fn (Get $get) => $get('kind') === 'vehicle')->required(fn (Get $get) => $get('kind') === 'vehicle'),
            TextInput::make('location')->label('Dónde está normalmente')->maxLength(255)->placeholder('Ej.: bodega Katy'),
        ]);
    }

    /** Only the editable fields of the contract, with empty strings as null like the HTTP path. */
    public static function toServiceInput(array $data): array
    {
        $kind = $data['kind'] ?? null;
        $location = trim((string) ($data['location'] ?? ''));

        return [
            'code' => trim((string) ($data['code'] ?? '')), 'name' => trim((string) ($data['name'] ?? '')), 'kind' => $kind,
            'status' => $data['status'] ?? 'active', 'location' => $location === '' ? null : $location,
            'skills' => $kind === 'staff' ? array_values($data['skills'] ?? []) : [],
            'character_id' => $kind === 'costume' ? ($data['character_id'] ?? null) : null,
            ...($kind === 'costume' ? ['outfit' => filled($data['outfit'] ?? null) ? (string) $data['outfit'] : null] : []),
            'equipment_kind' => $kind === 'equipment' ? ($data['equipment_kind'] ?? null) : null,
            'seats' => $kind === 'vehicle' && filled($data['seats'] ?? null) ? (int) $data['seats'] : null,
        ];
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
                // Retired units (e.g. old costume codes replaced by per-outfit ones) stay for history but out of the daily list.
                Filter::make('hide_inactive')->label('Ocultar inactivos')->toggle()->default()
                    ->query(fn ($query) => $query->where('resources.status', '!=', 'inactive')),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Todavía no hay staff ni trajes')
            ->emptyStateDescription('Se cargan con el inventario de César: cada persona, cada traje (por ejemplo, 2 de Mickey), cada bocina y cada vehículo.');
    }

    public static function getPages(): array
    {
        $pages = ['index' => ListResourceUnits::route('/')];
        // Routes always exist; canCreate()/canEdit() answer 403 until the audited service is available.
        $pages['create'] = CreateResource::route('/nuevo');
        $pages['edit'] = EditResource::route('/{record}/editar');

        return $pages;
    }
}
