<?php

namespace App\Filament\Resources\Characters;

use App\Filament\Resources\Characters\Pages\ListCharacters;
use App\Models\Character;
use App\Filament\Resources\Characters\Pages\{CreateCharacter, EditCharacter};
use App\Filament\Support\RecordServicePageGate;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\{TagsInput, TextInput, Toggle};

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
    protected static ?string $breadcrumb = 'Personajes';
    protected static bool $hasTitleCaseModelLabel = false;
    protected static ?int $navigationSort = 20;

    // Writable only once Codex's audited service exists (docs/RECORD-SERVICES.md); until then the list is read-only.
    public static function canCreate(): bool
    {
        return RecordServicePageGate::ready(\App\Services\Records\SaveCharacter::class);
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
            TextInput::make('name')->label('Nombre del personaje')->required()->maxLength(255),
            Toggle::make('active')->label('En catálogo')->default(true)->inline(false)
                ->helperText('Apágalo para dejar de ofrecerlo sin borrar su historial.'),
            TagsInput::make('outfits')->label('Versiones del traje (opcional)')->columnSpanFull()->placeholder('Ej.: Disneyland, Carreras')
                ->helperText('Solo si este personaje tiene trajes distintos que las clientas piden por nombre. Escribe una y presiona Enter.'),
        ]);
    }

    /** Only the editable fields of the contract, with empty strings as null like the HTTP path. */
    public static function toServiceInput(array $data): array
    {
        $outfits = collect($data['outfits'] ?? [])->map(fn ($o) => trim((string) $o))->filter(fn ($o) => $o !== '')->unique(fn ($o) => mb_strtolower($o))->values()->all();

        return ['name' => trim((string) ($data['name'] ?? '')) ?: null, 'active' => (bool) ($data['active'] ?? false), 'outfits' => $outfits === [] ? null : $outfits];
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
        $pages = ['index' => ListCharacters::route('/')];
        // Routes always exist; canCreate()/canEdit() answer 403 until the audited service is available.
        $pages['create'] = CreateCharacter::route('/nuevo');
        $pages['edit'] = EditCharacter::route('/{record}/editar');

        return $pages;
    }
}
