<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Customer;
use App\Filament\Resources\Customers\Pages\{CreateCustomer, EditCustomer, ViewCustomer};
use App\Filament\Support\RecordServicePageGate;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\{Select, TextInput};

use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

// Read-only until writes go through the audited backend service (docs/PHASE1-API.md).
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;
    protected static ?string $slug = 'clientas';
    protected static ?string $modelLabel = 'cliente';
    protected static ?string $pluralModelLabel = 'clientes';
    protected static ?string $navigationLabel = 'Clientes';
    protected static string|\UnitEnum|null $navigationGroup = 'Vender';
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;
    protected static ?string $breadcrumb = 'Clientes';
    protected static bool $hasTitleCaseModelLabel = false;
    protected static ?int $navigationSort = 10;

    // Writable only once Codex's audited service exists (docs/RECORD-SERVICES.md); until then the list is read-only.
    public static function canCreate(): bool
    {
        return RecordServicePageGate::ready(\App\Services\Records\SaveCustomer::class);
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
            TextInput::make('name')->label('Nombre completo')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('phone')->label('Teléfono')->tel()->maxLength(40)->helperText('Con código de área, como lo escribe el cliente.'),
            TextInput::make('email')->label('Correo')->email()->maxLength(255),
            Select::make('locale')->label('Idioma para sus mensajes')->options(['es' => 'Español', 'en' => 'Inglés'])->default('es')->required()->native(false)
                ->helperText('Los correos y textos le llegan en este idioma.'),
        ]);
    }

    /** Only the editable fields of the contract, with empty strings as null like the HTTP path. */
    public static function toServiceInput(array $data): array
    {
        $blank = fn ($v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v);

        return ['name' => $blank($data['name'] ?? null), 'email' => $blank($data['email'] ?? null), 'phone' => $blank($data['phone'] ?? null), 'locale' => $data['locale'] ?? 'es'];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->select('customers.*')
                ->selectSub(fn ($s) => $s->from('events')->selectRaw('count(*)')->whereColumn('events.customer_id', 'customers.id'), 'events_total'))
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable()->weight('bold'),
                TextColumn::make('phone')->label('Teléfono')->searchable()->placeholder('—'),
                TextColumn::make('email')->label('Correo')->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('locale')->label('Idioma')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'en' ? 'Inglés' : 'Español')
                    ->color(fn (string $state) => $state === 'en' ? 'info' : 'gray'),
                TextColumn::make('events_total')->label('Eventos')->sortable()->alignEnd(),
                TextColumn::make('created_at')->label('Desde')->date('j M Y', 'America/Chicago')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('locale')->label('Idioma')->options(['es' => 'Español', 'en' => 'Inglés']),
            ])
            ->recordUrl(fn ($record) => static::getUrl('view', ['record' => $record]))
            ->defaultSort('name')
            ->emptyStateHeading('Todavía no hay clientes')
            ->emptyStateDescription('Aparecen aquí cuando creas una reserva. Para agregarlas desde esta pantalla falta el siguiente paso (formularios).');
    }

    public static function getPages(): array
    {
        $pages = ['index' => ListCustomers::route('/')];
        // Routes always exist; canCreate()/canEdit() answer 403 until the audited service is available.
        $pages['create'] = CreateCustomer::route('/nuevo');
        $pages['edit'] = EditCustomer::route('/{record}/editar');
        $pages['view'] = ViewCustomer::route('/{record}');

        return $pages;
    }
}
