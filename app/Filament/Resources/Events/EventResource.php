<?php

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Support\Labels;
use App\Models\Event;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

// Read-only. Confirming capacity stays in the backend transaction (POST /admin/api/events/{id}/confirm-capacity).
class EventResource extends Resource
{
    protected static ?string $model = Event::class;
    protected static ?string $slug = 'eventos';
    protected static ?string $modelLabel = 'evento';
    protected static ?string $pluralModelLabel = 'eventos';
    protected static ?string $navigationLabel = 'Eventos';
    protected static string|\UnitEnum|null $navigationGroup = 'Día a día';
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;
    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('customer')->select('events.*')
                ->selectSub(fn ($s) => $s->from('event_services')->join('price_versions', 'price_versions.id', '=', 'event_services.price_version_id')->selectRaw('sum(price_versions.amount_cents)')->whereColumn('event_services.event_id', 'events.id'), 'services_cents'))
            ->columns([
                TextColumn::make('starts_at')->label('Fecha y hora')->sortable()
                    ->formatStateUsing(fn ($state) => Labels::when($state))
                    ->description(fn ($record) => 'hasta '.$record->ends_at->setTimezone(Labels::TZ)->format('g:i a')),
                TextColumn::make('title')->label('Evento')->searchable()->weight('bold')
                    ->description(fn ($record) => $record->customer?->name),
                TextColumn::make('postal_code')->label('ZIP')->placeholder('—'),
                TextColumn::make('travel_fee_cents')->label('Costo de transporte')
                    ->formatStateUsing(fn ($state) => Labels::money($state))->placeholder('Pendiente')
                    ->color(fn ($record) => $record->travel_fee_cents === null ? 'warning' : null),
                TextColumn::make('total')->label('Total')
                    ->state(fn ($record) => $record->services_cents === null ? null : ($record->travel_fee_cents === null ? null : (int) $record->services_cents + $record->travel_fee_cents))
                    ->formatStateUsing(fn ($state) => Labels::money($state))
                    ->placeholder(fn ($record) => $record->services_cents === null ? 'Sin servicios' : 'Falta transporte')
                    ->alignEnd(),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn ($state) => Labels::label(Labels::EVENT_STATUS, $state))
                    ->color(fn ($state) => Labels::EVENT_STATUS_COLOR[$state] ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(Labels::EVENT_STATUS),
            ])
            ->defaultSort('starts_at')
            ->emptyStateHeading('Todavía no hay eventos')
            ->emptyStateDescription('Los eventos aparecen aquí cuando se crean. La reserva rápida es el siguiente paso.');
    }

    public static function getPages(): array
    {
        return ['index' => ListEvents::route('/')];
    }
}
