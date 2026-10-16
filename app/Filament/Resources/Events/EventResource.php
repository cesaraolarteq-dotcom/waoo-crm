<?php

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Events\Pages\{ConfirmCapacity, CreateEvent, EditEvent, EventContract, EventMoney, ListEvents, ViewEvent};
use App\Filament\Support\{ChicagoTime, RecordServicePageGate};
use App\Models\{Customer, PriceVersion, ServiceVariant};
use Filament\Forms\Components\{DatePicker, Radio, Repeater, Select, TextInput, TimePicker};
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
        return static::canCreate() && $record->status === 'draft'
            && !(\Illuminate\Support\Facades\Schema::hasTable('contracts') && \App\Models\Contract::where('event_id', $record->getKey())->where('state', 'signed')->exists());
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
                Select::make('event_type')->label('Tipo de evento')->native(false)->options(self::EVENT_TYPES)
                    ->helperText('Elígelo tú (no se adivina). Solo los cumpleaños reciben el correo «Un año después».'),
                DatePicker::make('date')->label('Fecha')->native()->required()->live(),
                TimePicker::make('start_time')->label('Empieza (hora de Houston)')->native()->seconds(false)->required()->live(),
                TimePicker::make('end_time')->label('Termina')->native()->seconds(false)->required()->live(),
                $choice('date', 'start_time'),
                $choice('date', 'end_time'),
            ]),
            Section::make('Servicios')->columnSpanFull()->schema([
                Select::make('price_version_ids')->label('Paquetes y servicios')->multiple()->native(false)->searchable()
                    ->options(fn (?Event $record) => self::priceOptions($record))
                    ->helperText('Precio vigente de cada servicio. Un borrador conserva el precio que ya tenía; para usar el precio nuevo, quita el anterior y elige el actual.')
                    ->live(),
                Repeater::make('characters')->label('Personajes')->defaultItems(0)->columns(3)->addActionLabel('Agregar personaje')
                    ->visible(fn (Get $get) => self::configurablePrices($get('price_version_ids') ?? []) !== [])
                    ->helperText(fn (Get $get) => self::charactersHint($get('price_version_ids') ?? []))
                    ->schema([
                        Select::make('price_version_id')->label('Para el servicio')->native(false)->required()->live()
                            ->options(fn (Get $get) => self::configurablePrices($get('../../price_version_ids') ?? [])),
                        Select::make('character_id')->label('Personaje')->native(false)->required()->searchable()
                            ->options(fn (Get $get) => self::characterOptions($get('price_version_id'))),
                        TextInput::make('quantity')->label('Cuántos')->integer()->minValue(1)->maxValue(20)->default(1)->required(),
                    ]),
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

    /**
     * Newest price of each non-retired service, plus (when editing) the prices this draft already holds,
     * so an unrelated edit keeps the agreed price and repricing is a deliberate choice (Codex review EVENT-BLOCK-FORMS).
     */
    public static function priceOptions(?Event $event = null): array
    {
        $label = fn ($variant, $price) => $variant->name.' · '.Labels::duration($variant->duration_minutes).' · '.Labels::money($price->amount_cents);
        $options = ServiceVariant::query()->where('status', '!=', 'retired')->orderBy('name')->get()
            ->mapWithKeys(function ($variant) use ($label) {
                $price = PriceVersion::where('service_variant_id', $variant->id)->orderByDesc('id')->first();

                return $price ? [$price->id => $label($variant, $price).($variant->status === 'draft' ? ' (borrador)' : '')] : [];
            })->all();
        if ($event?->exists) {
            foreach ($event->services()->get() as $service) {
                if (! isset($options[$service->price_version_id])) {
                    $price = PriceVersion::find($service->price_version_id);
                    $variant = ServiceVariant::find($service->service_variant_id);
                    if ($price && $variant) {
                        $options[$service->price_version_id] = $label($variant, $price).' (precio guardado en este borrador)';
                    }
                }
            }
        }

        return $options;
    }

    public const EVENT_TYPES = ['birthday' => 'Cumpleaños', 'baby_shower' => 'Baby shower', 'gender_reveal' => 'Gender reveal', 'corporate' => 'Corporativo', 'other' => 'Otro'];

    /** Selected price versions whose service includes configurable characters: id => service name. */
    public static function configurablePrices(array $priceIds): array
    {
        return PriceVersion::query()->whereIn('price_versions.id', array_map('intval', $priceIds))
            ->join('service_variants', 'service_variants.id', '=', 'price_versions.service_variant_id')
            ->where('service_variants.character_quantity', '>', 0)->pluck('service_variants.name', 'price_versions.id')->all();
    }

    public static function characterOptions(?string $priceId): array
    {
        $variant = $priceId ? ServiceVariant::find(PriceVersion::whereKey((int) $priceId)->value('service_variant_id')) : null;
        $ids = collect($variant?->character_options ?? [])->pluck('character_id')->all();

        return \App\Models\Character::query()->whereIn('id', $ids)->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function charactersHint(array $priceIds): string
    {
        return collect(self::configurablePrices($priceIds))->keys()->map(function ($id) {
            $v = ServiceVariant::find(PriceVersion::whereKey($id)->value('service_variant_id'));

            return $v->name.': incluye '.$v->character_quantity.' '.($v->character_quantity === 1 ? 'personaje' : 'personajes');
        })->implode(' · ').'. Si faltan, el evento queda incompleto y no se puede confirmar.';
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
            'event_type' => $data['event_type'] ?? null,
            'services' => self::servicesInput($data),
        ];
    }

    /** price_version_ids + characters form state -> services[] for SaveEventDraft / QuickBook. */
    public static function servicesInput(array $data): array
    {
        return collect($data['price_version_ids'] ?? [])->map(function ($id) use ($data) {
            $service = ['price_version_id' => (int) $id];
            if (array_key_exists($id, self::configurablePrices([$id]))) {
                // Explicit list for configurable services (even empty), so the backend never keeps stale choices silently.
                $service['characters'] = collect($data['characters'] ?? [])->filter(fn ($c) => (int) ($c['price_version_id'] ?? 0) === (int) $id)
                    ->map(fn ($c) => ['character_id' => (int) $c['character_id'], 'quantity' => (int) $c['quantity']])->values()->all();
            }

            return $service;
        })->values()->all();
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
            'event_type' => $event->event_type,
            'characters' => $event->services()->with('characters')->get()->flatMap(fn ($s) => $s->characters->map(fn ($c) => [
                'price_version_id' => (string) $s->price_version_id, 'character_id' => (string) $c->character_id, 'quantity' => $c->quantity]))->values()->all(),
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
            ->recordUrl(fn ($record) => static::getUrl('view', ['record' => $record]))
            ->defaultSort('starts_at')
            ->emptyStateHeading('Todavía no hay eventos')
            ->emptyStateDescription('Los eventos aparecen aquí cuando se crean. La reserva rápida es el siguiente paso.');
    }

    public static function getPages(): array
    {
        // Routes always exist; canCreate()/canEdit() answer 403 until the audited service is available.
        return ['index' => ListEvents::route('/'), 'create' => CreateEvent::route('/nuevo'), 'edit' => EditEvent::route('/{record}/editar'), 'confirm' => ConfirmCapacity::route('/{record}/confirmar'), 'money' => EventMoney::route('/{record}/dinero'), 'costs' => \App\Filament\Resources\Events\Pages\EventCosts::route('/{record}/costos'), 'contract' => EventContract::route('/{record}/contrato'), 'view' => ViewEvent::route('/{record}')];
    }
}
