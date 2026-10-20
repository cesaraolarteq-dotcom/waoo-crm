<?php

namespace App\Filament\Resources\Variants;

use App\Filament\Resources\Variants\Pages\ListServiceVariants;
use App\Filament\Support\Labels;
use App\Models\ServiceVariant;
use App\Filament\Resources\Variants\Pages\{CreateServiceVariant, EditServiceVariant};
use App\Filament\Support\RecordServicePageGate;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\{Repeater, Select, Textarea, TextInput};
use Filament\Schemas\Components\Utilities\Get;

use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

// Read-only. Prices are append-only versions; the list shows the newest one.
class ServiceVariantResource extends Resource
{
    protected static ?string $model = ServiceVariant::class;
    protected static ?string $slug = 'catalogo';
    protected static ?string $modelLabel = 'servicio';
    protected static ?string $pluralModelLabel = 'servicios';
    protected static ?string $navigationLabel = 'Catálogo';
    protected static string|\UnitEnum|null $navigationGroup = 'Vender';
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;
    protected static ?string $breadcrumb = 'Catálogo';
    protected static bool $hasTitleCaseModelLabel = false;
    protected static ?int $navigationSort = 20;

    // Writable only once Codex's audited service exists (docs/RECORD-SERVICES.md); until then the list is read-only.
    public static function canCreate(): bool
    {
        return RecordServicePageGate::ready(\App\Services\Records\SaveVariant::class);
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
            TextInput::make('name')->label('Nombre del servicio')->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('long_description')->label('Descripción para el contrato (en inglés)')->rows(6)->maxLength(10000)->columnSpanFull()
                ->helperText('Cómo se desarrolla el servicio, con tus palabras. Se copia al contrato cuando fijas los montos; los contratos ya emitidos no cambian.'),
            TextInput::make('duration_minutes')->label('Duración (minutos)')->integer()->minValue(1)->maxValue(1440)->required()
                ->helperText('Ej.: 60 = 1 h; 90 = 1 h 30 min.'),
            TextInput::make('setup_minutes')->label('Montaje (minutos antes)')->integer()->minValue(0)->maxValue(720)
                ->helperText('Cuánto antes llega el equipo a montar. Vacío = aún no lo sabes. Se propone al crear la reserva; ahí lo puedes cambiar.'),
            TextInput::make('breakdown_minutes')->label('Desmontaje (minutos después)')->integer()->minValue(0)->maxValue(720)
                ->helperText('Cuánto se queda después para recoger. Vacío = aún no lo sabes.'),
            Select::make('status')->label('Estado')->options(Labels::VARIANT_STATUS)->default('draft')->required()->native(false)
                ->helperText('Para activarlo, define primero lo que necesita.'),
            Repeater::make('requirements')->label('Qué necesita (al mismo tiempo, todo el evento)')->columnSpanFull()->defaultItems(0)
                ->addActionLabel('Agregar requisito')->columns(4)
                ->schema(self::requirementFields()),
            TextInput::make('character_quantity')->label('Personajes incluidos')->integer()->minValue(0)->maxValue(20)->default(0)->live()
                ->helperText('0 si el paquete no deja elegir personaje. Si incluye, al crear el evento se eligen cuáles.'),
            Repeater::make('character_options')->label('Personajes que se pueden elegir')->columnSpanFull()->defaultItems(0)
                ->visible(fn (Get $get) => (int) $get('character_quantity') > 0)->addActionLabel('Agregar personaje permitido')
                ->itemLabel(fn (array $state) => filled($state['character_id'] ?? null) ? \App\Models\Character::whereKey($state['character_id'])->value('name') : null)
                ->schema([
                    Select::make('character_id')->label('Personaje')->native(false)->searchable()->required()
                        ->options(fn () => \App\Models\Character::query()->where('active', true)->orderBy('name')->pluck('name', 'id')),
                    Repeater::make('requirements')->label('Qué necesita cada uno de este personaje')->defaultItems(1)->columns(4)->addActionLabel('Agregar')
                        ->helperText('Ej.: 1 persona con rol Personaje + 1 traje de este personaje. Un personaje sin traje (p. ej. animador disfrazado) no necesita traje.')
                        ->schema(self::requirementFields()),
                ]),
        ]);
    }

    /** One requirement row (base recipe or one selected character). */
    public static function requirementFields(): array
    {
        return [
            Select::make('kind')->label('Tipo')->options(Labels::KIND)->required()->native(false)->live(),
            TextInput::make('quantity')->label('Cantidad')->integer()->minValue(1)->maxValue(20)->default(1)->required(),
            Select::make('skill')->label('Rol')->options(Labels::SKILL)->native(false)
                ->visible(fn (Get $get) => $get('kind') === 'staff')->required(fn (Get $get) => $get('kind') === 'staff'),
            Select::make('character_id')->label('Personaje')->native(false)->searchable()
                ->options(fn () => \App\Models\Character::query()->orderBy('name')->pluck('name', 'id'))
                ->visible(fn (Get $get) => $get('kind') === 'costume')->required(fn (Get $get) => $get('kind') === 'costume'),
            Select::make('equipment_kind')->label('Equipo')->options(Labels::EQUIPMENT)->native(false)
                ->visible(fn (Get $get) => $get('kind') === 'equipment')->required(fn (Get $get) => $get('kind') === 'equipment'),
        ];
    }

    /** Only the editable fields of the contract, with empty strings as null like the HTTP path. */
    public static function toServiceInput(array $data): array
    {
        $quantity = (int) ($data['character_quantity'] ?? 0);
        $options = $quantity > 0 ? collect(array_values($data['character_options'] ?? []))->map(fn ($o) => [
            'character_id' => (int) ($o['character_id'] ?? 0), 'requirements' => self::normalizeRequirements($o['requirements'] ?? []),
        ])->all() : [];

        $long = trim((string) ($data['long_description'] ?? ''));

        return ['name' => trim((string) ($data['name'] ?? '')), 'long_description' => $long === '' ? null : $long, 'status' => $data['status'] ?? 'draft',
            'duration_minutes' => (int) ($data['duration_minutes'] ?? 0), 'requirements' => self::normalizeRequirements($data['requirements'] ?? []),
            'character_quantity' => $quantity, 'character_options' => $options,
            // PACKAGE-TIMING: blank = unknown (null), 0 is a real zero.
            'setup_minutes' => self::minutesOrNull($data['setup_minutes'] ?? null), 'breakdown_minutes' => self::minutesOrNull($data['breakdown_minutes'] ?? null)];
    }

    public static function minutesOrNull(mixed $value): ?int
    {
        return $value === null || trim((string) $value) === '' ? null : (int) $value;
    }

    /** Only the key for each requirement kind, ints as ints (contract shape). */
    public static function normalizeRequirements(array $rows): array
    {
        $requirements = [];
        foreach (array_values($rows) as $r) {
            $kind = $r['kind'] ?? null;
            $requirements[] = array_filter([
                'kind' => $kind, 'quantity' => (int) ($r['quantity'] ?? 1),
                'skill' => $kind === 'staff' ? ($r['skill'] ?? null) : null,
                'character_id' => $kind === 'costume' && filled($r['character_id'] ?? null) ? (int) $r['character_id'] : null,
                'equipment_kind' => $kind === 'equipment' ? ($r['equipment_kind'] ?? null) : null,
            ], fn ($v) => $v !== null);
        }

        return $requirements;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->select('service_variants.*')
                ->selectSub(fn ($s) => $s->from('price_versions')->select('amount_cents')->whereColumn('price_versions.service_variant_id', 'service_variants.id')->orderByDesc('id')->limit(1), 'current_price_cents'))
            ->columns([
                TextColumn::make('name')->label('Servicio')->searchable()->sortable()->weight('bold'),
                TextColumn::make('duration_minutes')->label('Duración')->formatStateUsing(fn ($state) => Labels::duration($state))->sortable(),
                TextColumn::make('current_price_cents')->label('Precio vigente')
                    ->state(fn ($record) => $record->current_price_cents === null ? null : (int) $record->current_price_cents)
                    ->formatStateUsing(fn ($state) => Labels::money($state))->placeholder('Sin precio')
                    ->description('+ costo de transporte')->alignEnd(),
                TextColumn::make('requirements')->label('Necesita')
                    ->state(fn ($record) => self::needs($record->requirements ?? []))->placeholder('Sin definir')->wrap()
                    // The character's performer (and costume) is added per booking from the chosen character, so say it here.
                    ->description(fn ($record) => ($record->character_quantity ?? 0) > 0
                        ? '+ '.($record->character_quantity === 1 ? 'el personaje que elijas, con su traje' : $record->character_quantity.' personajes que elijas, con sus trajes')
                        : null),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn ($state) => Labels::label(Labels::VARIANT_STATUS, $state))
                    ->color(fn ($state) => Labels::VARIANT_STATUS_COLOR[$state] ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(Labels::VARIANT_STATUS),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('El catálogo está vacío')
            ->emptyStateDescription('Se llena con la hoja de catálogo de César (precios, personal y equipo por paquete). No se inventa ningún precio.');
    }

    /** @param array<int, array<string, mixed>> $requirements */
    public static function needs(array $requirements): ?string
    {
        $parts = [];
        foreach ($requirements as $r) {
            $what = match ($r['kind'] ?? null) {
                'staff' => Labels::label(Labels::SKILL, $r['skill'] ?? null),
                'costume' => 'traje',
                'equipment' => Labels::label(Labels::EQUIPMENT, $r['equipment_kind'] ?? null),
                'vehicle' => 'vehículo',
                default => '?',
            };
            $parts[] = ($r['quantity'] ?? 1).' '.mb_strtolower($what);
        }

        return $parts ? implode(' · ', $parts) : null;
    }

    public static function getPages(): array
    {
        $pages = ['index' => ListServiceVariants::route('/')];
        // Routes always exist; canCreate()/canEdit() answer 403 until the audited service is available.
        $pages['create'] = CreateServiceVariant::route('/nuevo');
        $pages['edit'] = EditServiceVariant::route('/{record}/editar');

        return $pages;
    }
}
