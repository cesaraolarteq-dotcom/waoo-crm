<?php

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Events\Pages\{CreateEvent, EditEvent, ListEvents};
use App\Filament\Support\{ChicagoTime, RecordServicePageGate};
use App\Models\{Customer, PriceVersion, ServiceVariant};
use Filament\Forms\Components\{DatePicker, Radio, Select, TextInput, TimePicker};
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
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
    protected static ?string $breadcrumb = 'Eventos';
    protected static bool $hasTitleCaseModelLabel = false;
    protected static ?int $navigationSort = 10;

    // Draft events only, through SaveEventDraft. Confirming capacity (staff, deposit) stays in its own backend flow.
    public static function canCreate(): bool
    {
        return RecordServicePageGate::ready(\App\Services\Records\SaveEventDraft::class);
    }

    public static function canEdit(Model $record): bool
    {
        return static::canCreate() && $record->status === 'draft';
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $choice = fn (string $date, string $time) => Radio::make("{$time}_choice")->label('Esa hora se repite ese día (cambio de horario): ¿cuál?')
            ->options(ChicagoTime::CHOICES)->required()->columnSpanFull()
            ->visible(fn (Get $get) => ChicagoTime::isAmbiguous($get($date), $get($time)));

        return $schema->columns(2)->components([
            Section::make('La fiesta')->columns(2)->columnSpanFull()->schema([
                Select::make('customer_id')->label('Clienta')->required()->native(false)->searchable()
                    ->options(fn () => Customer::query()->orderBy('name')->pluck('name', 'id')),
                TextInput::make('title')->label('Nombre del evento')->required()->maxLength(255)->placeholder('Ej.: Cumpleaños de Sofía'),
                DatePicker::make('date')->label('Fecha')->native()->required()->live(),
                TimePicker::make('start_time')->label('Empieza (hora de Houston)')->native()->seconds(false)->required()->live(),
                TimePicker::make('end_time')->label('Termina')->native()->seconds(false)->required()->live(),
                $choice('date', 'start_time'),
                $choice('date', 'end_time'),
            ]),
            Section::make('Servicios')->columnSpanFull()->schema([
                Select::make('price_version_ids')->label('Paquetes y servicios')->multiple()->native(false)->searchable()
                    ->options(fn () => self::priceOptions())
                    ->helperText('Precio vigente de cada servicio. Queda guardado tal cual en el evento aunque el precio cambie después.'),
            ]),
            Section::make('Lugar y logística')->columns(2)->columnSpanFull()->schema([
                TextInput::make('address')->label('Dirección')->maxLength(255)->columnSpanFull(),
                TextInput::make('postal_code')->label('ZIP')->maxLength(10)->placeholder('77494'),
                TextInput::make('travel_fee')->label('Costo de transporte (USD)')->prefix('$')
                    ->rule('nullable')->rule('regex:/^\d{1,7}(\.\d{1,2})?$/')
                    ->validationMessages(['regex' => 'Escribe solo el número, por ejemplo 40 o 40.50.'])
                    ->helperText('Déjalo vacío si aún no está calculado: se muestra "Pendiente", nunca $0.'),
                TextInput::make('setup_minutes')->label('Montaje (minutos antes)')->integer()->minValue(0)->maxValue(720),
                TextInput::make('breakdown_minutes')->label('Desmontaje (minutos después)')->integer()->minValue(0)->maxValue(720),
            ]),
        ]);
    }

    /** Newest price of each non-retired service: price_version_id => "Name · 1 h · $X". */
    public static function priceOptions(): array
    {
        return ServiceVariant::query()->where('status', '!=', 'retired')->orderBy('name')->get()
            ->mapWithKeys(function ($variant) {
                $price = PriceVersion::where('service_variant_id', $variant->id)->orderByDesc('id')->first();

                return $price ? [$price->id => $variant->name.' · '.Labels::duration($variant->duration_minutes).' · '.Labels::money($price->amount_cents).($variant->status === 'draft' ? ' (borrador)' : '')] : [];
            })->all();
    }

    /** Form state -> SaveEventDraft input (explicit offsets, cents, null travel fee when unknown). */
    public static function toServiceInput(array $data): array
    {
        $start = ChicagoTime::toIso($data['date'], $data['start_time'], $data['start_time_choice'] ?? null, 'data.start_time');
        $end = ChicagoTime::toIso($data['date'], $data['end_time'], $data['end_time_choice'] ?? null, 'data.end_time');
        if (strtotime($end) <= strtotime($start)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['data.end_time' => 'La hora de fin debe ser después de la de inicio (mismo día).']);
        }
        $fee = trim((string) ($data['travel_fee'] ?? ''));
        $blankToNull = fn ($v) => ($v === null || trim((string) $v) === '') ? null : $v;

        return [
            'customer_id' => (int) $data['customer_id'], 'title' => trim((string) $data['title']),
            'starts_at' => $start, 'ends_at' => $end, 'timezone' => ChicagoTime::TZ,
            'address' => $blankToNull($data['address'] ?? null), 'postal_code' => $blankToNull($data['postal_code'] ?? null),
            'setup_minutes' => filled($data['setup_minutes'] ?? null) ? (int) $data['setup_minutes'] : null,
            'breakdown_minutes' => filled($data['breakdown_minutes'] ?? null) ? (int) $data['breakdown_minutes'] : null,
            'travel_fee_cents' => $fee === '' ? null : \App\Filament\Resources\Variants\Pages\EditServiceVariant::toCents($fee),
            'services' => collect($data['price_version_ids'] ?? [])->map(fn ($id) => ['price_version_id' => (int) $id])->values()->all(),
        ];
    }

    /** Stored event -> form state. */
    public static function toFormState(Event $event): array
    {
        [$date, $start, $startChoice] = ChicagoTime::toLocalParts($event->starts_at);
        [, $end, $endChoice] = ChicagoTime::toLocalParts($event->ends_at);

        return [
            'customer_id' => $event->customer_id, 'title' => $event->title, 'date' => $date,
            'start_time' => $start, 'start_time_choice' => $startChoice, 'end_time' => $end, 'end_time_choice' => $endChoice,
            'address' => $event->address, 'postal_code' => $event->postal_code,
            'setup_minutes' => $event->setup_minutes, 'breakdown_minutes' => $event->breakdown_minutes,
            'travel_fee' => $event->travel_fee_cents === null ? null : rtrim(rtrim(number_format($event->travel_fee_cents / 100, 2, '.', ''), '0'), '.'),
            'price_version_ids' => $event->services()->pluck('price_version_id')->all(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('customer')->select('events.*')
                ->selectSub(fn ($s) => $s->from('event_services')->join('price_versions', 'price_versions.id', '=', 'event_services.price_version_id')->selectRaw('sum(price_versions.amount_cents)')->whereColumn('event_services.event_id', 'events.id'), 'services_cents'))
            ->columns([
                TextColumn::make('starts_at')->label('Fecha y hora')->sortable()
                    ->formatStateUsing(fn ($state) => Labels::when($state))
                    ->description(fn ($record) => 'hasta '.$record->ends_at->setTimezone(Labels::TZ)->locale('es')->translatedFormat('g:i a')),
                TextColumn::make('title')->label('Evento')->searchable()->weight('bold')
                    ->description(fn ($record) => $record->customer?->name),
                TextColumn::make('postal_code')->label('ZIP')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('travel_fee_cents')->label('Costo de transporte')->visibleFrom('lg')
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
        // Routes always exist; canCreate()/canEdit() answer 403 until the audited service is available.
        return ['index' => ListEvents::route('/'), 'create' => CreateEvent::route('/nuevo'), 'edit' => EditEvent::route('/{record}/editar')];
    }
}
