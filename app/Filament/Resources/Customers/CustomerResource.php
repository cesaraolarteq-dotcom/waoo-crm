<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Customer;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

// Read-only until writes go through the audited backend service (docs/PHASE1-API.md).
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;
    protected static ?string $slug = 'clientas';
    protected static ?string $modelLabel = 'clienta';
    protected static ?string $pluralModelLabel = 'clientas';
    protected static ?string $navigationLabel = 'Clientas';
    protected static string|\UnitEnum|null $navigationGroup = 'Vender';
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;
    protected static ?string $breadcrumb = 'Clientas';
    protected static bool $hasTitleCaseModelLabel = false;
    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
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
            ->defaultSort('name')
            ->emptyStateHeading('Todavía no hay clientas')
            ->emptyStateDescription('Aparecen aquí cuando creas una reserva. Para agregarlas desde esta pantalla falta el siguiente paso (formularios).');
    }

    public static function getPages(): array
    {
        return ['index' => ListCustomers::route('/')];
    }
}
