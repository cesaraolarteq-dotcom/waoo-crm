<?php

namespace App\Filament\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Variants\Pages\EditServiceVariant;
use App\Filament\Support\{ChicagoTime, Labels, RecordServicePageGate};
use App\Models\{Customer, PriceVersion, ServiceVariant};
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\{Checkbox, DatePicker, Hidden, Radio, Repeater, Select, Textarea, TextInput, TimePicker};
use Filament\Pages\Page;
use Filament\Schemas\Components\{Actions, Callout, EmbeddedSchema, Form, Grid, Section};
use Filament\Schemas\Components\Utilities\{Get, Set};
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

// BOOK-QUICK, owner half (docs/plans/QUICK-BOOKING.md). One form, one call to the proposed atomic
// App\Services\Booking\QuickBook (docs/QUICK-BOOKING-API-PROPOSAL.md). Off until that class exists. Never sends anything.
class QuickBooking extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;
    protected static ?string $navigationLabel = 'Reserva rápida';
    protected static ?int $navigationSort = -10;
    protected static ?string $slug = 'reserva-rapida';
    protected static ?string $title = 'Reserva rápida';
    protected string $view = 'filament.pages.quick-booking';

    public const SERVICE = 'App\\Services\\Booking\\QuickBook';

    public ?array $data = [];

    /** Result shown once after saving (the client link is never stored in the page afterwards). */
    public ?array $result = null;

    #[Locked]
    public string $key = '';

    #[Locked]
    public string $linkExpiryAnchorUtc = '';

    /** The registered client as shown when the owner picked her: id, version and contact. Only the server sets these. */
    #[Locked]
    public ?int $displayedCustomerId = null;

    #[Locked]
    public ?int $displayedCustomerVersion = null;

    #[Locked]
    public ?array $displayedContact = null;

    #[Locked] public string $clientSearch = '';

    /** LEAD-01: the website lead being converted, as it was when this page opened (id + version, server-set only). */
    #[Locked]
    public ?int $leadId = null;

    #[Locked]
    public ?int $leadVersion = null;

    #[Locked]
    public ?array $leadSummary = null;

    public static function canAccess(): bool
    {
        return parent::canAccess() && \App\Filament\Support\FinanceGate::enabled() && config('waoo.sandbox_documents', false) && RecordServicePageGate::ready(self::SERVICE);
    }

    public static function getNavigationBadge(): ?string
    {
        return null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->key = (string) Str::uuid();
        $this->linkExpiryAnchorUtc = now('UTC')->startOfSecond()->toIso8601String();
        $this->form->fill(['customer_mode' => 'existing', 'locale' => 'es', 'price_version_ids' => [], 'deposit' => ['method' => 'zelle', 'date' => now(ChicagoTime::TZ)->format('Y-m-d')],
            'expires_days' => 14, 'characters' => []]);
        $this->fillFromLead(request()->integer('lead') ?: null);
    }

    public function getSubheading(): ?string
    {
        return 'Tu mitad en un minuto. El cliente completa sus datos y firma con el enlace. Nada se envía solo: tú copias el mensaje.';
    }

    public function form(Schema $schema): Schema
    {
        $choice = fn (string $time) => Radio::make("{$time}_choice")->label('Esa hora se repite ese día (cambio de horario): ¿cuál?')
            ->options(ChicagoTime::CHOICES)->required()->columnSpanFull()
            ->visible(fn (Get $get) => ChicagoTime::isAmbiguous($get('date'), $get($time)));

        return $schema->statePath('data')->components([
            Section::make('1. Paquete')->columns(2)->schema([
                // Owner 2026-10-20: almost always one package, so a single picker that closes on choosing; extras are a second, optional list.
                Select::make('package_id')->label('Paquete')->native(false)->searchable()->placeholder('Elige el paquete')
                    ->options(fn () => EventResource::priceOptions())->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->syncServices($get, $set)),
                Select::make('extra_ids')->label('Extras (opcional)')->multiple()->native(false)->searchable()->placeholder('Agregar otro servicio')
                    ->options(fn (Get $get) => array_diff_key(EventResource::priceOptions(), [(string) $get('package_id') => true]))->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->syncServices($get, $set)),
                Hidden::make('price_version_ids'),
                ...EventResource::characterFields(),
                Select::make('event_type')->label('Tipo de evento')->native(false)->required()->options(EventResource::EVENT_TYPES),
                EventResource::titleField(),
                Hidden::make('title_suggested')->dehydrated(false),
            ]),
            Section::make('2. Fecha y lugar')->columns(3)->schema([
                // Owner 2026-10-21: past days greyed out, days with parties marked in the calendar (see the page view), and what is booked that day.
                DatePicker::make('date')->label('Fecha')->native(false)->displayFormat('D j M Y')->locale('es')->weekStartsOnSunday()->closeOnDateSelection()->required()->live()
                    ->minDate(now(ChicagoTime::TZ)->startOfDay())->extraAttributes(['class' => 'waoo-date'])
                    ->helperText(fn (Get $get) => self::dayLine($get('date')))
                    ->afterStateUpdated(fn (Get $get, Set $set) => EventResource::refreshTitle($get, $set)),
                \App\Filament\Support\TimeSelect::make('start_time')->label('Empieza (Houston)')->required()->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->fillEnd($get, $set))
                    ->helperText('El cliente la confirma en su enlace.'),
                \App\Filament\Support\TimeSelect::make('end_time')->label('Termina')->required()->live()
                    ->helperText('Se llena con la duración del paquete.'),
                $choice('start_time'), $choice('end_time'),
                TextInput::make('setup_minutes')->label('Montaje (minutos antes)')->integer()->minValue(0)->maxValue(720)
                    ->helperText('Se llena con el catálogo del paquete; puedes cambiarlo. Vacío si algún servicio aún no lo tiene.'),
                TextInput::make('breakdown_minutes')->label('Desmontaje (minutos después)')->integer()->minValue(0)->maxValue(720),
                Hidden::make('setup_minutes_auto')->dehydrated(false), Hidden::make('breakdown_minutes_auto')->dehydrated(false),
                TextInput::make('address')->label('Dirección de la fiesta (opcional)')->maxLength(255)->columnSpan(2)
                    ->extraInputAttributes(['data-places' => 'address', 'autocomplete' => 'off'])
                    ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => \App\Filament\Support\TravelFeeFields::invalidate($get, $set))
                    ->placeholder('Calle, ciudad, TX ZIP')->helperText(fn () => filled(config('waoo.google_maps_browser_key')) ? 'Empieza a escribir y elige la dirección que sugiere Google: se llena el ZIP y se calcula el transporte. Puedes cambiar todo.' : 'Con la dirección exacta puedes calcular el transporte. El cliente la confirma en su enlace.'),
                TextInput::make('postal_code')->label('ZIP')->required()->maxLength(10)->placeholder('77494')->extraInputAttributes(['data-places' => 'zip'])
                    ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => \App\Filament\Support\TravelFeeFields::invalidate($get, $set)),
                \App\Filament\Support\TravelFeeFields::feeField(true, 'Obligatorio aquí: con el depósito se fija el total.',
                    fn (Get $get, Set $set) => self::refreshDeposit($get, $set))
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshDeposit($get, $set)), // a typed fee also moves the suggested deposit
                ...\App\Filament\Support\TravelFeeFields::extraFields(),
            ]),
            Section::make('3. Cliente')->columns(2)->schema([
                Hidden::make('customer_mode'),
                Hidden::make('customer_id')->live()->afterStateUpdated(function (Get $get, Set $set) { $this->fillCustomer($get, $set); EventResource::refreshTitle($get, $set); }),
                Select::make('client_lookup')->label('Cliente')->native(false)->searchable()->dehydrated(false)->columnSpanFull()
                    ->placeholder('Escribe nombre, teléfono o correo')
                    // Owner 2026-10-21: the client is optional; with no data the owner sends the link and the client fills everything in.
                    ->helperText('Opcional. Si no tienes sus datos, déjalo vacío: el cliente los llena en su enlace.')
                    ->options(['new' => 'Crear cliente nuevo'])
                    ->getSearchResultsUsing(fn (string $search) => $this->clientLookupOptions($search))
                    ->getOptionLabelUsing(fn ($value) => self::clientLookupLabel($value))
                    ->live()->afterStateUpdated(function (Get $get, Set $set) { $this->selectClientLookup($get, $set); }),
                Section::make('Verifica sus datos')->description('Abre aquí si necesitas corregir su nombre, teléfono o correo.')->collapsible()->collapsed()->columns(2)->columnSpanFull()
                    ->visible(fn (Get $get) => $get('customer_mode') === 'existing' && filled($get('customer_id')))->schema([
                TextInput::make('c_name')->label('Nombre')->required(fn () => ($this->displayedContact['name'] ?? null) !== null)->nullable()->maxLength(255)
                    ->visible(fn (Get $get) => $get('customer_mode') === 'existing' && filled($get('customer_id'))),
                TextInput::make('c_phone')->label('Teléfono / WhatsApp')->tel()->maxLength(40)
                    ->visible(fn (Get $get) => $get('customer_mode') === 'existing' && filled($get('customer_id'))),
                TextInput::make('c_email')->label('Correo')->email()->maxLength(255)
                    ->visible(fn (Get $get) => $get('customer_mode') === 'existing' && filled($get('customer_id'))),
                Select::make('c_locale')->label('Idioma de sus mensajes')->native(false)->options(['es' => 'Español', 'en' => 'Inglés'])->required()
                    ->visible(fn (Get $get) => $get('customer_mode') === 'existing' && filled($get('customer_id'))),
                    ]),
                // Owner 2026-10-21: phone and email are optional (sometimes the owner just sends the link herself); one of the three identifies the client.
                TextInput::make('name')->label('Nombre')->nullable()->maxLength(255)
                    ->live(debounce: 500)->afterStateUpdated(fn (Get $get, Set $set) => EventResource::refreshTitle($get, $set))
                    ->visible(fn (Get $get) => $get('customer_mode') === 'new')->helperText(fn (Get $get) => self::duplicateHint($get('name'), $get('phone')) ?? 'Confirma su nombre completo en el enlace.'),
                TextInput::make('phone')->label('Teléfono / WhatsApp')->tel()->maxLength(40)->live(debounce: 500)
                    ->visible(fn (Get $get) => $get('customer_mode') === 'new'),
                TextInput::make('email')->label('Correo')->email()->maxLength(255)->live(onBlur: true)
                    ->helperText('Todo opcional. Si no tienes nada, copias el enlace y se lo mandas tú.')
                    ->visible(fn (Get $get) => $get('customer_mode') === 'new'),
                Select::make('locale')->label('Idioma de sus mensajes')->native(false)->options(['es' => 'Español', 'en' => 'Inglés'])->required()
                    ->visible(fn (Get $get) => $get('customer_mode') === 'new'),
            ]),
            Section::make('4. Depósito recibido')->columns(3)->schema([
                Grid::make(3)->statePath('deposit')->columnSpanFull()->schema([
                    TextInput::make('amount')->label('Monto que llegó (USD)')->prefix('$')->required()->live(onBlur: true)
                        ->helperText('Se llena con el 50 % del total (servicios + transporte). Cámbialo si llegó otro monto.')
                        ->rule('regex:/^\d{1,7}(\.\d{1,2})?$/')->validationMessages(['regex' => 'Escribe solo el número, por ejemplo 250.'])
                        ->afterStateUpdated(fn (Get $get, Set $set) => $set('amount_custom', filled($get('amount')))),
                    Hidden::make('amount_custom')->default(false)->dehydrated(false),
                    TextInput::make('reference')->label('Referencia de Zelle (opcional)')->minLength(3)->maxLength(255),
                    DatePicker::make('date')->label('Fecha en que llegó')->native(false)->displayFormat('D j M Y')->locale('es')->weekStartsOnSunday()->closeOnDateSelection()
                        ->required()->live()->maxDate(now(ChicagoTime::TZ))->helperText('Hoy, salvo que llegara otro día.'),
                    Checkbox::make('verified')->label('Lo vi reflejado en el banco')->accepted()->columnSpanFull(),
                ]),
                Callout::make('Resumen')->info()->columnSpanFull()->description(fn (Get $get) => $this->moneyLine($get)),
                TextInput::make('expires_days')->label('El enlace vence en (días)')->integer()->minValue(1)->maxValue(60)->required(),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')->footer([
                Actions::make([Action::make('save')->label('Guardar reserva y crear enlace')->color('primary')->submit('save')]),
            ])->visible(fn () => $this->result === null),
        ]);
    }

    /** Package + extras → price_version_ids (what the rest of the page and QuickBook use); characters of a removed service go too. */
    public function syncServices(Get $get, Set $set): void
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', [$get('package_id'), ...((array) ($get('extra_ids') ?? []))]))));
        $set('price_version_ids', $ids);
        $set('characters', collect($get('characters') ?? [])->filter(fn ($c) => blank($c['price_version_id'] ?? null) || in_array((string) $c['price_version_id'], $ids, true))->all());
        EventResource::syncCharacterSlots($get, $set);
        self::refreshDeposit($get, $set);
        $this->fillEnd($get, $set);
        EventResource::refreshTitle($get, $set);
        EventResource::refreshTiming($get, $set);
    }

    /** Houston day => number of events, for the next year (marks busy days in the date picker). */
    public static function busyDays(): array
    {
        $from = now(ChicagoTime::TZ)->startOfDay()->toImmutable();
        return \App\Models\Event::query()->where('starts_at', '>=', $from->utc())->where('starts_at', '<', $from->addYear()->utc())
            ->pluck('starts_at')->countBy(fn ($at) => \Carbon\CarbonImmutable::parse($at)->setTimezone(ChicagoTime::TZ)->format('Y-m-d'))->all();
    }

    /** "Ese día ya tienes 2 fiestas: 2:00 PM Mágico Rumi · 5:00 PM Waoo" — or that the day is free. */
    public static function dayLine(mixed $date): ?string
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) return 'Los días con un punto ya tienen fiestas.';
        $day = \Carbon\CarbonImmutable::parse(substr($date, 0, 10), ChicagoTime::TZ);
        $events = \App\Models\Event::query()->where('starts_at', '>=', $day->utc())->where('starts_at', '<', $day->addDay()->utc())->orderBy('starts_at')->get(['starts_at', 'title']);
        if ($events->isEmpty()) return 'Ese día está libre.';
        $list = $events->map(fn ($e) => $e->starts_at->setTimezone(ChicagoTime::TZ)->format('g:i A').' '.$e->title)->implode(' · ');
        return 'Ese día ya '.($events->count() === 1 ? 'tienes 1 fiesta: ' : 'tienes '.$events->count().' fiestas: ').$list;
    }

    /** Non-native time pickers keep "Y-m-d H:i:s" in raw state; the rest of the page works with Houston "H:i". */
    public static function hm(?string $time): ?string
    {
        return $time !== null && preg_match('/(\d{2}:\d{2})(?::\d{2})?$/', trim($time), $m) ? $m[1] : null;
    }

    public function fillEnd(Get $get, Set $set): void
    {
        $start = self::hm($get('start_time'));
        $minutes = PriceVersion::query()->whereIn('price_versions.id', array_map('intval', $get('price_version_ids') ?? []))
            ->join('service_variants', 'service_variants.id', '=', 'price_versions.service_variant_id')->max('service_variants.duration_minutes');
        if ($start && $minutes && preg_match('/^\d{2}:\d{2}/', $start)) {
            $set('end_time', CarbonImmutable::createFromFormat('H:i', substr($start, 0, 5))->addMinutes((int) $minutes)->format('H:i'));
        }
    }

    public static function depositDefault(array $priceIds, mixed $fee, int $extraCents = 0): ?string
    {
        $ids = array_values(array_unique(array_map('intval', $priceIds)));
        if ($ids === [] || is_bool($fee) || !is_scalar($fee) || !preg_match('/^\d{1,7}(\.\d{1,2})?$/D', (string) $fee)) return null;
        $prices = PriceVersion::whereIn('id', $ids)->get(['amount_cents', 'currency']);
        if ($prices->count() !== count($ids) || $prices->contains(fn ($price) => $price->currency !== 'USD')) return null;
        $total = (int) $prices->sum('amount_cents') + $extraCents + EditServiceVariant::toCents((string) $fee);
        $target = intdiv($total, 2) + $total % 2;
        return intdiv($target,100).'.'.str_pad((string)($target%100),2,'0',STR_PAD_LEFT);
    }

    public static function refreshDeposit(Get $get, Set $set): void
    {
        if ($get('deposit.amount_custom')) return;
        $set('deposit.amount', self::depositDefault($get('price_version_ids') ?? [], $get('travel_fee'), self::extraCents($get)));
    }

    /** Choosing a character past the included ones moves the suggested deposit too (unless the owner typed one). */
    public function updatedData(mixed $value, string $key): void
    {
        if (! str_starts_with($key, 'characters') && $key !== 'character_count') return;
        if ($this->data['deposit']['amount_custom'] ?? false) return;
        $extra = EventResource::extraCharacters(['price_version_ids' => $this->data['price_version_ids'] ?? [], 'characters' => $this->data['characters'] ?? []]);
        $this->data['deposit']['amount'] = self::depositDefault($this->data['price_version_ids'] ?? [], $this->data['travel_fee'] ?? null, $extra * (int) config('waoo.extra_character_cents'));
    }

    /** Extra characters × the per-character amount, from the live form (the backend recounts and snapshots it on save). */
    public static function extraCents(Get $get): int
    {
        return EventResource::extraCharacters(['price_version_ids' => $get('price_version_ids') ?? [], 'characters' => $get('characters') ?? []]) * (int) config('waoo.extra_character_cents');
    }

    /** Live money line: total, 50 %, received, balance and due date, before saving. Unknown pieces say so; never $0. */
    public function moneyLine(Get $get): string
    {
        $extra = self::extraCents($get);
        $services = (int) PriceVersion::whereIn('id', array_map('intval', $get('price_version_ids') ?? []))->sum('amount_cents') + $extra;
        $fee = $get('travel_fee');
        if (! $services) {
            return 'Elige el paquete para ver el total.';
        }
        if ($fee === null || $fee === '' || ! preg_match('/^\d{1,7}(\.\d{1,2})?$/', (string) $fee)) {
            return 'Servicios '.Labels::money($services).($extra ? ' (incluye personajes adicionales '.Labels::money($extra).')' : '').' + transporte pendiente: escribe el transporte para ver el total.';
        }
        $total = $services + EditServiceVariant::toCents((string) $fee);
        $target = intdiv($total, 2) + $total % 2;
        $amount = $get('deposit.amount');
        $paid = $amount && preg_match('/^\d{1,7}(\.\d{1,2})?$/', (string) $amount) ? EditServiceVariant::toCents((string) $amount) : 0;
        $line = 'Total '.Labels::money($total).($extra ? ' (incluye personajes adicionales '.Labels::money($extra).')' : '').' · depósito 50 % '.Labels::money($target).' · recibido '.Labels::money($paid).' · saldo '.Labels::money(max($total - $paid, 0));
        if ($paid && $paid < $target) {
            $line .= ' · faltan '.Labels::money($target - $paid).' para el 50 %';
        }
        if ($get('date') && self::hm($get('start_time')) && ($start = array_values(ChicagoTime::candidates($get('date'), self::hm($get('start_time'))))[0] ?? null)) {
            $line .= ' · el saldo vence '.Labels::when(CarbonImmutable::parse($start)->subHours(24));
        }

        return rtrim($line, '.').'.';
    }

    public function save(): void
    {
        $s = $this->form->getState();
        if (array_filter((array) ($s['price_version_ids'] ?? [])) === []) {
            throw ValidationException::withMessages(['data.package_id' => 'Elige el paquete.']);
        }
        $input = $this->toServiceInput($s);
        try {
            $r = app(self::SERVICE)->execute(Filament::auth()->user(), $input, $this->key);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['data.'.self::formPath($k) => $m])->all());
        } catch (RecordWriteRejected|AuthorizationException $e) {
            \Filament\Notifications\Notification::make()->danger()->title('No se guardó')->body($e->getMessage())->persistent()->send();

            return;
        }
        $this->result = self::presentResult($r, $s);
        $this->key = (string) Str::uuid();
        $this->leadId = null; $this->leadVersion = null; $this->leadSummary = null;
    }

    /** Search by name, phone (digits only) or email; label shows the phone so two people with the same name are told apart. */
    public static function searchCustomers(string $search): array
    {
        $term = trim($search);
        if (mb_strlen($term) < 2) return [];
        $digits = preg_replace('/\D/', '', $term);

        return Customer::query()->where(function ($q) use ($term, $digits) {
            $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%');
            if (strlen($digits) >= 3) $q->orWhereRaw("replace(replace(replace(replace(replace(coalesce(phone,''),'-',''),' ',''),'(',''),')',''),'+','') like ?", ['%'.$digits.'%']);
        })->orderBy('name')->limit(20)->get()->mapWithKeys(fn ($c) => [$c->id => self::customerLabel($c)])->all();
    }

    public static function customerLabel(?Customer $c): ?string
    {
        return $c ? ($c->name ?? 'Nombre pendiente').($c->phone ? ' · '.$c->phone : '').($c->email ? ' · '.$c->email : '') : null;
    }

    protected function clientLookupOptions(string $search): array
    {
        $this->clientSearch = mb_substr(trim($search), 0, 255);
        $options = [];
        foreach (self::possibleMatches($this->clientSearch, $this->clientSearch, $this->clientSearch) as $id => $label) {
            $options['customer:'.$id] = $label;
        }
        return [...$options, 'new' => 'Crear cliente nuevo con estos datos'];
    }

    public static function clientLookupLabel(mixed $value): ?string
    {
        if ($value === 'new') return 'Cliente nuevo';
        if (!is_string($value) || !preg_match('/^customer:([0-9]+)$/D', $value, $match)) return null;
        $customer = Customer::find((int) $match[1]);
        if (!$customer) return null;
        $count = \App\Models\Event::where('customer_id', $customer->id)->count();
        return self::customerLabel($customer).' · '.$count.($count === 1 ? ' evento' : ' eventos');
    }

    protected function selectClientLookup(Get $get, Set $set): void
    {
        $value = $get('client_lookup');
        if ($value === 'new') {
            $set('customer_mode', 'new'); $set('customer_id', null);
            $this->fillCustomer($get, $set);
            $search = $this->clientSearch;
            $email = str_contains($search, '@');
            $phone = !$email && preg_match('/^[+0-9() .-]+$/D', $search) && preg_match('/[0-9]/', $search);
            $set('name', $search !== '' && !$email && !$phone ? $search : null);
            $set('phone', $phone ? $search : null); $set('email', $email ? $search : null);
        } elseif (is_string($value) && preg_match('/^customer:([0-9]+)$/D', $value, $match)) {
            $set('customer_mode', 'existing'); $set('customer_id', (int) $match[1]);
            $this->fillCustomer($get, $set);
        } else {
            $set('customer_mode', 'existing'); $set('customer_id', null);
            $this->fillCustomer($get, $set);
        }
        EventResource::refreshTitle($get, $set);
    }

    public function fillCustomer(Get $get, Set $set): void
    {
        $c = filled($get('customer_id')) ? Customer::find((int) $get('customer_id')) : null;
        $this->displayedCustomerId = $c?->id;
        $this->displayedCustomerVersion = $c?->version;
        $this->displayedContact = $c ? ['name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'locale' => $c->locale] : null;
        $set('c_name', $c?->name); $set('c_phone', $c?->phone); $set('c_email', $c?->email); $set('c_locale', $c?->locale ?? 'es');
    }

    /** For a new client: warn when the phone or exact name already exists, so she is not registered twice. */
    public static function duplicateHint(?string $name, ?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        $match = Customer::query()->where(function ($q) use ($name, $digits) {
            $q->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $name))]);
            if (strlen($digits) >= 7) $q->orWhereRaw("replace(replace(replace(replace(replace(coalesce(phone,''),'-',''),' ',''),'(',''),')',''),'+','') like ?", ['%'.substr($digits, -10)]);
        })->first();

        return $match && (filled($name) || strlen($digits) >= 7) ? '⚠ Ya tienes registrada a '.self::customerLabel($match).'. Si es ella, selecciónala en la búsqueda de arriba.' : null;
    }

    /**
     * Registered clients that could be the one being typed: every word of the name (3+ letters) appears, the phone's last
     * 10 digits match, or the email matches. Up to 6, labelled with phone, email and how many events she has.
     */
    public static function possibleMatches(?string $name, ?string $phone, ?string $email): array
    {
        $fold = fn (?string $v) => mb_strtolower(\Illuminate\Support\Str::ascii(trim((string) $v)));
        $words = array_values(array_filter(preg_split('/\s+/', $fold($name)) ?: [], fn ($w) => strlen($w) >= 3));
        $digits = substr((string) preg_replace('/\D/', '', (string) $phone), -10);
        $mail = $fold($email);
        if ($words === [] && strlen($digits) < 4 && ! str_contains($mail, '@')) return [];
        // Compared in PHP with accents folded ("Maria Gonzalez" finds "María González") on any database.
        $ids = [];
        foreach (Customer::query()->select(['id', 'name', 'phone', 'email'])->orderBy('name')->cursor() as $c) {
            $hay = $fold($c->name);
            $byName = $words !== [] && collect($words)->every(fn ($w) => str_contains($hay, $w));
            $byPhone = strlen($digits) >= 4 && str_contains((string) preg_replace('/\D/', '', (string) $c->phone), $digits);
            $byMail = str_contains($mail, '@') && str_contains($fold($c->email), $mail);
            if ($byName || $byPhone || $byMail) $ids[] = $c->id;
            if (count($ids) >= 6) break;
        }

        return Customer::query()->whereIn('id', $ids)
            ->addSelect(['events_count' => \App\Models\Event::selectRaw('count(*)')->whereColumn('events.customer_id', 'customers.id')])->orderBy('name')->get()
            ->mapWithKeys(fn ($c) => [$c->id => self::customerLabel($c).' · '.((int) $c->events_count === 1 ? '1 evento' : (int) $c->events_count.' eventos')])->all();
    }

    /**
     * Corrected contact of a registered client, sent INSIDE the QuickBook call (QUICK-BOOK-CONTACT-API): booking and contact
     * commit or fail together. Version and id come from Locked state captured when she was picked, never from the browser.
     */
    public function customerUpdate(array $s): array
    {
        if ($this->displayedCustomerId === null || (int) ($s['customer_id'] ?? 0) !== $this->displayedCustomerId || $this->displayedContact === null) {
            return [];
        }
        $new = ['name' => blank($s['c_name'] ?? null) && $this->displayedContact['name'] === null ? null : trim((string) ($s['c_name'] ?? '')), 'email' => filled($s['c_email'] ?? null) ? trim($s['c_email']) : null,
            'phone' => filled($s['c_phone'] ?? null) ? trim($s['c_phone']) : null, 'locale' => $s['c_locale'] ?? $this->displayedContact['locale']];

        return $new === $this->displayedContact ? [] : ['customer_update' => ['expected_version' => (int) $this->displayedCustomerVersion, ...$new]];
    }

    /** Service field paths -> form paths (deposit.* and services.* stay; new_customer.* and event fields map to their inputs). */
    public static function formPath(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'new_customer.') => substr($key, strlen('new_customer.')),
            str_starts_with($key, 'customer_update.') => 'c_'.substr($key, strlen('customer_update.')),
            str_starts_with($key, 'deposit.amount_cents') => 'deposit.amount',
            str_starts_with($key, 'deposit.received_at'), str_starts_with($key, 'deposit.received_on') => 'deposit.date',
            str_starts_with($key, 'deposit.verified_received') => 'deposit.verified',
            $key === 'travel_fee_cents', $key === 'travel_fee_quote_id' => 'travel_fee',
            $key === 'starts_at' => 'start_time',
            $key === 'ends_at' => 'end_time',
            str_starts_with($key, 'services') => 'package_id',
            default => $key,
        };
    }

    public function toServiceInput(array $s): array
    {
        $start = ChicagoTime::toIso($s['date'], (string) self::hm($s['start_time']), $s['start_time_choice'] ?? null, 'data.start_time');
        $end = ChicagoTime::toIso($s['date'], (string) self::hm($s['end_time']), $s['end_time_choice'] ?? null, 'data.end_time');
        if (strtotime($end) <= strtotime($start)) {
            throw ValidationException::withMessages(['data.end_time' => 'La hora de fin debe ser después de la de inicio (mismo día).']);
        }
        $d = $s['deposit'];

        return [
            ...(($s['customer_mode'] ?? null) === 'existing' && filled($s['customer_id'] ?? null)
                ? ['customer_id' => (int) $s['customer_id'], ...$this->customerUpdate($s)]
                : ['new_customer' => ['name' => trim((string) ($s['name'] ?? '')), 'phone' => filled($s['phone'] ?? null) ? trim($s['phone']) : null, 'email' => filled($s['email'] ?? null) ? trim($s['email']) : null, 'locale' => $s['locale'] ?? 'es']]),
            'title' => trim((string) $s['title']), 'event_type' => $s['event_type'], 'starts_at' => $start, 'ends_at' => $end, 'timezone' => ChicagoTime::TZ,
            'postal_code' => trim((string) $s['postal_code']), 'travel_fee_cents' => EditServiceVariant::toCents((string) $s['travel_fee']),
            ...(filled($s['address'] ?? null) ? ['address' => trim((string) $s['address'])] : []),
            ...\App\Filament\Support\TravelFeeFields::input($s),
            ...($this->leadId !== null ? ['lead_id' => $this->leadId, 'lead_expected_version' => (int) $this->leadVersion] : []),
            ...(filled($s['setup_minutes'] ?? null) ? ['setup_minutes' => (int) $s['setup_minutes']] : []),
            ...(filled($s['breakdown_minutes'] ?? null) ? ['breakdown_minutes' => (int) $s['breakdown_minutes']] : []),
            'services' => EventResource::servicesInput($s),
            'deposit' => ['method' => 'zelle', 'amount_cents' => EditServiceVariant::toCents((string) $d['amount']), 'reference' => filled($d['reference'] ?? null) ? trim($d['reference']) : null,
                'received_on' => $d['date'], 'verified_received' => (bool) ($d['verified'] ?? false)],
            // The pre-request snapshot retains this anchor even when the save response is lost.
            'portal' => ['expires_at' => min(CarbonImmutable::parse($this->linkExpiryAnchorUtc)->setTimezone(ChicagoTime::TZ)->addDays((int) $s['expires_days'])->endOfDay(), CarbonImmutable::parse($this->linkExpiryAnchorUtc)->addDays(30))->format('Y-m-d\TH:i:sP')],
        ];
    }

    /** Builds the one-time result panel, including the ready-to-copy messages (no sending). */
    public static function presentResult(object $r, array $s): array
    {
        $first = trim(explode(' ', (string) ($r->customer->name ?? ''))[0] ?? '');
        $helloEs = $first === '' ? '¡Hola!' : "¡Hola {$first}!";
        $helloEn = $first === '' ? 'Hi!' : "Hi {$first}!";
        $f = $r->finance;
        $when = Labels::when($r->event->starts_at);
        $paid = Labels::money($f['net_received_cents']);
        $balance = Labels::money($f['balance_cents']);
        $due = Labels::when($f['financial_revision']->balance_due_at);
        $link = $r->portal_url;
        $exp = $r->portal_expires_at ? Labels::when(CarbonImmutable::parse($r->portal_expires_at)) : '';
        $whenEn = CarbonImmutable::instance($r->event->starts_at)->setTimezone(ChicagoTime::TZ)->format('D, M j \a\t g:i a');

        return [
            'event_id' => $r->event->id, 'link' => $link, 'expires' => $exp, 'shown_before' => $link === null && !($r->link_issue_pending ?? false),
            'link_pending' => (bool) ($r->link_issue_pending ?? false),
            'summary' => 'Total '.Labels::money($f['financial_revision']->total_cents).' · recibido '.$paid.' · saldo '.$balance.' (vence '.$due.')',
            'msg_es' => "{$helloEs} Gracias por tu depósito de {$paid}. Registramos tu solicitud para el {$when}. Para terminar tu reserva, completa tus datos y firma tu contrato aquí: {$link} (el enlace vence el {$exp}). Tu saldo es de {$balance}. ¡Estamos emocionados de ser parte de tu celebración!",
            'msg_en' => "{$helloEn} Thank you for your deposit of {$paid}. We recorded your booking request for {$whenEn}. To finish your booking, please complete your details and sign your contract here: {$link} (link expires {$exp}). Your remaining balance is {$balance}. We're excited to be part of your celebration!",
            'locale' => $r->customer->locale ?? 'es',
            'phone' => $r->customer->phone ?? null,
            // Costume hold attempted right after the deposit (Codex V1); shown so the owner knows whether the costume is held.
            'hold' => ($h = (array) ($r->costume_hold ?? [])) && ($h['state'] ?? 'not_required') !== 'not_required' ? [
                'label' => \App\Filament\Resources\Events\Pages\ViewEvent::HOLD_STATE[$h['state']] ?? $h['state'],
                'ok' => $h['state'] === 'held' && ($h['issues'] ?? []) === [],
                'issues' => array_values(array_unique(array_map(fn ($i) => \App\Filament\Resources\Events\Pages\ViewEvent::HOLD_ISSUES[$i] ?? 'Requiere revisión.', $h['issues'] ?? [])))] : null,
        ];
    }

    /**
     * Prefill from a website lead (never trusted blindly: the owner still picks package/price, checks time, fee and deposit).
     * A single duplicate hint selects that registered client; otherwise she starts as a new client with the lead's contact.
     */
    public function fillFromLead(?int $id): void
    {
        if (! $id || ! (bool) config('waoo.sandbox_lead_intake', false) || ! class_exists(\App\Services\Leads\ReadLeads::class)) return;
        $lead = app(\App\Services\Leads\ReadLeads::class)->show(Filament::auth()->user(), $id);
        if (! in_array($lead['status'], ['new', 'contacted', 'quoted'], true)) {
            \Filament\Notifications\Notification::make()->warning()->title('Este prospecto ya no se puede convertir')->body('Está '.(\App\Filament\Pages\Leads::STATUS[$lead['status']] ?? $lead['status']).'.')->send();

            return;
        }
        $p = $lead['submission']['payload'];
        $this->leadId = $lead['id']; $this->leadVersion = $lead['version'];
        $this->leadSummary = ['name' => $p['name'], 'package' => $p['package'] ?? '', 'type' => $p['event_type'] ?? '', 'travel' => $p['travel_estimate_text'] ?? null, 'message' => $p['message'] ?? null];
        $state = ['date' => $p['event_date'] ?? null, 'start_time' => $p['start_time'] ?? null, 'postal_code' => $p['zip'] ?? null,
            // Website event type (English label) → CRM type; anything unknown stays for the owner to choose.
            'event_type' => \App\Filament\Pages\Leads::EVENT_TYPES[$p['event_type'] ?? ''][0] ?? null];
        $hints = $lead['duplicate_hints'] ?? [];
        if (count($hints) === 1) {
            $state += ['customer_mode' => 'existing', 'customer_id' => $hints[0]['customer_id'], 'client_lookup' => 'customer:'.$hints[0]['customer_id']];
        } else {
            $state += ['customer_mode' => 'new', 'client_lookup' => 'new', 'name' => $p['name'], 'phone' => $p['phone'] ?? null, 'email' => $p['email'] ?? null, 'locale' => $p['locale'] ?? 'es'];
        }
        $this->form->fill([...$this->form->getRawState(), ...$state]);
        if (($state['customer_mode'] ?? null) === 'existing') {
            $c = \App\Models\Customer::find($state['customer_id']);
            $this->displayedCustomerId = $c?->id; $this->displayedCustomerVersion = $c?->version;
            $this->displayedContact = $c ? ['name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'locale' => $c->locale] : null;
            $this->form->fill([...$this->form->getRawState(), 'c_name' => $c?->name, 'c_phone' => $c?->phone, 'c_email' => $c?->email, 'c_locale' => $c?->locale ?? 'es']);
        }
    }

    public function startOver(): void
    {
        $this->result = null;
        $this->mount();
    }
}
