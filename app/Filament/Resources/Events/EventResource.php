<?php

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Events\Pages\{ConfirmCapacity, CreateEvent, EditEvent, EventContract, EventMoney, ListEvents, ViewEvent};
use App\Filament\Support\{ChicagoTime, RecordServicePageGate};
use App\Models\{Customer, PriceVersion, ServiceVariant};
use Filament\Forms\Components\{DatePicker, Hidden, Radio, Repeater, Select, TextInput, TimePicker};
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\{Get, Set};
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
                    ->options(fn () => Customer::query()->orderBy('name')->pluck('name', 'id'))
                    ->live()->afterStateUpdated(fn (Get $get, Set $set) => self::refreshTitle($get, $set)),
                self::titleField(),
                Hidden::make('title_suggested')->dehydrated(false),
                Select::make('event_type')->label('Tipo de evento')->native(false)->options(self::EVENT_TYPES)
                    ->helperText('Elígelo tú (no se adivina). Solo los cumpleaños reciben el correo «Un año después».'),
                DatePicker::make('date')->label('Fecha')->native()->required()->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshTitle($get, $set)),
                TimePicker::make('start_time')->label('Empieza (hora de Houston)')->native(false)->seconds(false)->minutesStep(15)->required()->live(),
                TimePicker::make('end_time')->label('Termina')->native(false)->seconds(false)->minutesStep(15)->required()->live(),
                $choice('date', 'start_time'),
                $choice('date', 'end_time'),
            ]),
            Section::make('Servicios')->columnSpanFull()->schema([
                Select::make('price_version_ids')->label('Paquetes y servicios')->multiple()->native(false)->searchable()
                    ->options(fn (?Event $record) => self::priceOptions($record))
                    ->helperText('Precio vigente de cada servicio. Un borrador conserva el precio que ya tenía; para usar el precio nuevo, quita el anterior y elige el actual.')
                    ->live()->afterStateUpdated(function (Get $get, Set $set) { self::syncCharacterSlots($get, $set); self::refreshTitle($get, $set); self::refreshTiming($get, $set); }),
                ...self::characterFields(),
            ]),
            Section::make('Lugar y logística')->columns(2)->columnSpanFull()->schema([
                TextInput::make('address')->label('Dirección')->maxLength(255)->columnSpanFull()
                    ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => \App\Filament\Support\TravelFeeFields::invalidate($get, $set)),
                TextInput::make('postal_code')->label('ZIP')->maxLength(10)->placeholder('77494')
                    ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => \App\Filament\Support\TravelFeeFields::invalidate($get, $set)),
                \App\Filament\Support\TravelFeeFields::feeField(false, 'Déjalo vacío si aún no está calculado: se muestra "Pendiente", nunca $0.'),
                ...\App\Filament\Support\TravelFeeFields::extraFields(),
                TextInput::make('setup_minutes')->label('Montaje (minutos antes)')->integer()->minValue(0)->maxValue(720)
                    ->helperText('Se propone con el catálogo del paquete; puedes cambiarlo.'),
                TextInput::make('breakdown_minutes')->label('Desmontaje (minutos después)')->integer()->minValue(0)->maxValue(720),
                Hidden::make('setup_minutes_auto')->dehydrated(false), Hidden::make('breakdown_minutes_auto')->dehydrated(false),
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
            ->sortBy(fn ($variant) => [self::popularRank($variant->name), $variant->name])
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

    /** Owner 2026-10-20: the best sellers first, in this order; everything else alphabetical after them. */
    public const POPULAR = ['estrella', 'mágico', 'música y juegos', 'música y juegos 2.0', 'waoo'];

    public static function popularRank(string $name): int
    {
        $base = mb_strtolower(trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $name)));
        $rank = array_search($base, self::POPULAR, true);

        return $rank === false ? 99 : $rank;
    }

    /** Costume versions of a character (OUTFIT-01); empty for single-version characters, so the selector stays hidden. */
    public static function outfitOptions($characterId): array
    {
        return filled($characterId) ? collect(\App\Models\Character::find((int) $characterId)?->outfits ?? [])->mapWithKeys(fn ($o) => [$o => $o])->all() : [];
    }

    /** Same slot controls in quick booking and drafts; one picker per physical character requested. */
    public static function characterFields(): array
    {
        return [
            TextInput::make('character_count')->label('Cuántos')->integer()->minValue(1)->maxValue(100)
                ->dehydrated(false)->live(onBlur: true)
                ->visible(fn (Get $get) => self::configurablePrices($get('price_version_ids') ?? []) !== [])
                ->afterStateUpdated(function (Get $get, Set $set) {
                    self::syncCharacterSlots($get, $set, true); self::refreshTitle($get, $set);
                }),
            Hidden::make('character_count_auto')->dehydrated(false),
            Repeater::make('characters')->label('Personajes')->defaultItems(0)->columns(2)->columnSpanFull()
                ->addable(false)->deletable(false)->reorderable(false)
                ->visible(fn (Get $get) => self::configurablePrices($get('price_version_ids') ?? []) !== [])
                ->helperText(fn (Get $get) => self::charactersHint($get('price_version_ids') ?? []))
                ->schema([
                    Select::make('price_version_id')->label('Para')->native(false)->live()->dehydratedWhenHidden()
                        ->required(fn (Get $get) => filled($get('character_id')))
                        ->default(fn (Get $get) => count($p = self::configurablePrices($get('../../price_version_ids') ?? [])) === 1 ? (string) array_key_first($p) : null)
                        ->visible(fn (Get $get) => count(self::configurablePrices($get('../../price_version_ids') ?? [])) > 1)
                        ->options(fn (Get $get) => self::configurablePrices($get('../../price_version_ids') ?? [])),
                    Select::make('character_id')->label('Personaje')->native(false)->searchable()->nullable()
                        ->options(fn (Get $get) => self::characterOptions($get('price_version_id'), null, $get('character_id')))
                        ->live()->afterStateUpdated(function (Get $get, Set $set) {
                            $set('outfit', null); self::refreshTitle($get, $set, '../../');
                        }),
                    Hidden::make('quantity')->default(1),
                    Select::make('outfit')->label('Versión del traje (opcional)')->native(false)->placeholder('Cualquiera')
                        ->options(fn (Get $get) => self::outfitOptions($get('character_id')))
                        ->visible(fn (Get $get) => self::outfitOptions($get('character_id')) !== [])
                        ->helperText('Solo aparece en los detalles internos y para el staff, nunca en el nombre del evento.'),
                    TextInput::make('note')->label('Nota del personaje (solo la ves tú)')->maxLength(200),
                ]),
        ];
    }

    public static function expandCharacterSlots(array $rows): array
    {
        $expanded = [];
        foreach ($rows as $key => $row) {
            $quantity = max(1, min(20, (int) ($row['quantity'] ?? 1)));
            for ($i = 0; $i < $quantity; $i++) {
                $expanded[$i === 0 ? $key : (string) \Illuminate\Support\Str::uuid()] = [...$row, 'quantity' => 1];
            }
        }
        return $expanded;
    }

    public static function syncCharacterSlots(Get $get, Set $set, bool $manualCount = false): void
    {
        $prices = self::configurablePrices($get('price_version_ids') ?? []);
        $rows = collect(self::expandCharacterSlots($get('characters') ?? []))
            ->filter(fn ($row) => blank($row['price_version_id'] ?? null) || array_key_exists($row['price_version_id'], $prices))->all();
        $expected = (int) PriceVersion::whereIn('price_versions.id', array_keys($prices))->join('service_variants', 'service_variants.id', '=', 'price_versions.service_variant_id')->sum('service_variants.character_quantity');
        $current = $get('character_count');
        $desired = $prices === [] ? 0 : ((!$manualCount && (blank($current) || (string) $current === (string) $get('character_count_auto')))
            ? $expected : max(0, min(100, (int) $current)));
        $rows = array_slice($rows, 0, $desired, true);
        while (count($rows) < $desired) $rows[(string) \Illuminate\Support\Str::uuid()] = ['price_version_id' => null, 'character_id' => null, 'quantity' => 1, 'outfit' => null, 'note' => null];
        if (count($prices) === 1) foreach ($rows as &$row) $row['price_version_id'] = (string) array_key_first($prices);
        unset($row);
        $set('characters', $rows); $set('character_count', $desired);
        $set('character_count_auto', $manualCount ? null : $expected);
    }

    public static function titleField(): TextInput
    {
        return TextInput::make('title')->label('Nombre del evento')->required()->maxLength(255)
            ->placeholder('Ej.: Mágico Rumi 04/10 Claudia Martínez')
            ->helperText('Se llena solo: paquete + personajes + fecha + clienta. Si lo cambias a mano, ya no se toca.');
    }

    /**
     * Owner's naming convention: package + characters + short date (MM/DD, plus /YY when it is not this year) + client.
     * Example: "Mágico (Magic) Rumi 04/10 Claudia Martínez".
     */
    public static function suggestTitle(array $priceVersionIds, array $characters, ?string $date, ?string $client): string
    {
        $ids = array_values(array_filter(array_map('intval', $priceVersionIds)));
        $variants = PriceVersion::query()->whereIn('price_versions.id', $ids)
            ->join('service_variants', 'service_variants.id', '=', 'price_versions.service_variant_id')
            ->pluck('service_variants.name', 'price_versions.id');
        $packages = collect($ids)->map(fn ($id) => $variants[$id] ?? null)->filter()->implode(' + ');
        $names = collect($characters)->filter(fn ($c) => filled($c['character_id'] ?? null))
            ->map(function ($c) {
                $name = \App\Models\Character::whereKey((int) $c['character_id'])->value('name');
                $n = max(1, (int) ($c['quantity'] ?? 1));


                return $name ? ($n > 1 ? "{$n} {$name}" : $name) : null;
            })->filter()->values();
        $chars = $names->count() > 1 ? $names->slice(0, -1)->implode(', ').' y '.$names->last() : (string) $names->first();
        $short = null;
        if ($date && preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            $d = \Carbon\CarbonImmutable::parse(substr($date, 0, 10), ChicagoTime::TZ);
            $short = $d->format('m/d').($d->year !== now(ChicagoTime::TZ)->year ? $d->format('/y') : '');
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([$packages, $chars, $short, trim((string) $client)]))));
    }

    /**
     * PACKAGE-TIMING prefill: catalog setup/breakdown (max of the chosen services, null when any is unknown). A value the owner
     * typed is kept; a value we filled follows the selection (and clears when the selection empties). Never touches duration.
     */
    public static function refreshTiming(Get $get, Set $set, string $p = ''): void
    {
        $defaults = \App\Services\Records\PackageTimingDefaults::forPriceIds(array_values(array_filter((array) ($get($p.'price_version_ids') ?? []))));
        foreach (['setup_minutes', 'breakdown_minutes'] as $field) {
            $current = $get($p.$field);
            $auto = $get($p.$field.'_auto');
            if ($current === null || trim((string) $current) === '' || (string) $current === (string) $auto) {
                $set($p.$field, $defaults[$field] === null ? null : (string) $defaults[$field]);
            }
            $set($p.$field.'_auto', $defaults[$field] === null ? null : (string) $defaults[$field]);
        }
    }

    /** Fill the title from the suggestion unless the owner already typed her own. $p = path prefix from inside a repeater. */
    public static function refreshTitle(Get $get, Set $set, string $p = ''): void
    {
        $client = $get($p.'customer_mode') === 'new'
            ? $get($p.'name')
            : (filled($get($p.'customer_id')) ? Customer::whereKey((int) $get($p.'customer_id'))->value('name') : null);
        $suggestion = self::suggestTitle((array) ($get($p.'price_version_ids') ?? []), (array) ($get($p.'characters') ?? []), $get($p.'date'), $client);
        $current = trim((string) $get($p.'title'));
        // Follows the suggestion while the owner hasn't typed her own, including back to empty when the package is removed.
        if ($current === '' || $current === trim((string) $get($p.'title_suggested'))) {
            $set($p.'title', $suggestion);
        }
        $set($p.'title_suggested', $suggestion);
    }

    public const EVENT_TYPES = ['birthday' => 'Cumpleaños', 'baby_shower' => 'Baby shower', 'gender_reveal' => 'Gender reveal', 'corporate' => 'Corporativo', 'other' => 'Otro'];

    /** Selected price versions whose service includes configurable characters: id => service name. */
    public static function configurablePrices(array $priceIds): array
    {
        return PriceVersion::query()->whereIn('price_versions.id', array_map('intval', $priceIds))
            ->join('service_variants', 'service_variants.id', '=', 'price_versions.service_variant_id')
            ->where('service_variants.character_quantity', '>', 0)->pluck('service_variants.name', 'price_versions.id')->all();
    }

    /**
     * Characters allowed by the package, grouped "Categoría · Show" (owner 2026-10-19) and filtered by audience
     * (girls → girls + all, boys → boys + all). The current selection always stays listed, even if the filter hides it.
     */
    public static function characterOptions(?string $priceId, ?string $audience = null, $selected = null): array
    {
        $variant = $priceId ? ServiceVariant::find(PriceVersion::whereKey((int) $priceId)->value('service_variant_id')) : null;
        $ids = collect($variant?->character_options ?? [])->pluck('character_id')->all();
        $rows = \App\Models\Character::query()->whereIn('id', $ids)->where(fn ($q) => $q->where('active', true)->orWhere('id', (int) $selected))
            ->get(['id', 'name', 'category', 'group', 'audience'])
            ->filter(fn ($c) => ! in_array($audience, ['girls', 'boys'], true) || in_array($c->audience ?? 'all', [$audience, 'all'], true) || (int) $c->id === (int) $selected);
        $order = array_flip(\App\Filament\Resources\Characters\CharacterResource::CATEGORIES);
        $label = fn ($c) => collect([$c->category, $c->group])->filter()->implode(' · ') ?: 'Sin grupo';

        return $rows->sortBy(fn ($c) => [$order[$c->category ?? ''] ?? 99, (string) $c->category, (string) $c->group, $c->name])
            ->groupBy($label)->map(fn ($g) => $g->pluck('name', 'id')->all())->all();
    }

    /** "Mostrar" filter next to each character picker; not saved. */
    public static function audienceFilter(): Select
    {
        return Select::make('audience_filter')->label('Mostrar')->native(false)->dehydrated(false)->live()->selectablePlaceholder(false)
            ->options(['all' => 'Todos', 'girls' => 'Para niñas', 'boys' => 'Para niños'])->default('all');
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
            ...\App\Filament\Support\TravelFeeFields::input($data),
        ];
    }

    /** price_version_ids + characters form state -> services[] for SaveEventDraft / QuickBook. */
    public static function servicesInput(array $data): array
    {
        $configurable = self::configurablePrices($data['price_version_ids'] ?? []);
        $solePrice = count($configurable) === 1 ? array_key_first($configurable) : 0;
        return collect($data['price_version_ids'] ?? [])->map(function ($id) use ($data, $configurable, $solePrice) {
            $service = ['price_version_id' => (int) $id];
            if (array_key_exists($id, $configurable)) {
                // Explicit list for configurable services (even empty), so the backend never keeps stale choices silently.
                $service['characters'] = collect($data['characters'] ?? [])->filter(fn ($c) => filled($c['character_id'] ?? null) && (int) ($c['price_version_id'] ?? $solePrice) === (int) $id)
                    ->map(fn ($c) => ['character_id' => (int) $c['character_id'], 'quantity' => (int) $c['quantity'],
                        // OUTFIT-01: optional exact costume version and private owner note, kept on every explicit row.
                        'outfit' => filled($c['outfit'] ?? null) ? (string) $c['outfit'] : null,
                        'note' => filled($c['note'] ?? null) ? trim((string) $c['note']) : null])
                    ->groupBy(fn ($row) => json_encode([$row['character_id'], $row['outfit'], $row['note']], JSON_THROW_ON_ERROR))
                    ->map(fn ($rows) => [...$rows->first(), 'quantity' => (int) $rows->sum('quantity')])->values()->all();
            }

            return $service;
        })->values()->all();
    }

    /** Stored event -> form state. */
    public static function toFormState(Event $event): array
    {
        [$date, $start, $startChoice] = ChicagoTime::toLocalParts($event->starts_at);
        [, $end, $endChoice] = ChicagoTime::toLocalParts($event->ends_at);

        $slots = array_values(self::expandCharacterSlots($event->services()->with('characters')->get()->flatMap(fn ($s) => $s->characters->map(fn ($c) => [
            'price_version_id' => (string) $s->price_version_id, 'character_id' => (string) $c->character_id, 'quantity' => $c->quantity,
            'outfit' => $c->outfit, 'note' => $c->note]))->values()->all()));

        return [
            'customer_id' => $event->customer_id, 'title' => $event->title, 'date' => $date,
            'start_time' => $start, 'start_time_choice' => $startChoice, 'end_time' => $end, 'end_time_choice' => $endChoice,
            'address' => $event->address, 'postal_code' => $event->postal_code, 'travel_has_evidence' => filled($event->travel_fee_evidence ?? null) ? '1' : null,
            'setup_minutes' => $event->setup_minutes, 'breakdown_minutes' => $event->breakdown_minutes,
            'travel_fee' => $event->travel_fee_cents === null ? null : rtrim(rtrim(number_format($event->travel_fee_cents / 100, 2, '.', ''), '0'), '.'),
            'price_version_ids' => $event->services()->pluck('price_version_id')->all(),
            'event_type' => $event->event_type,
            'character_count' => count($slots), 'characters' => $slots,
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
