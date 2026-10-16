<?php

namespace App\Filament\Resources\Variants;

use App\Filament\Resources\Variants\Pages\ListServiceVariants;
use App\Filament\Support\Labels;
use App\Models\ServiceVariant;
use Filament\Resources\Resource;
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

    public static function canCreate(): bool
    {
        return false;
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
                    ->state(fn ($record) => self::needs($record->requirements ?? []))->placeholder('Sin definir')->wrap(),
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
        return ['index' => ListServiceVariants::route('/')];
    }
}
