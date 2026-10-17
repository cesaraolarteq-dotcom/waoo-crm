<?php

namespace App\Filament\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\{ChicagoTime, Labels};
use App\Models\{Customer, ImportBatch, ImportedProjectLink, ImportRow, ImportServiceMapping, PriceVersion, ServiceVariant};
use App\Services\Finance\MutationKey;
use App\Services\Imports\{CommitHoneyBookEvent, FinalizeHoneyBookImport, ReadImportCoverage, StageHoneyBookFutureEvents};
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\{Checkbox, Radio, Repeater, Select, TextInput};
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\{Actions, EmbeddedSchema, Form, Section};
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\{Locked, Url};
use Livewire\WithFileUploads;

// IMPORT-01 owner screen over Codex's fixed 24-column HoneyBook import (docs/IMPORT-FUTURE-V1-API.md).
// Upload → private preview + reconciliation → explicit per-row import (client, packages, characters) → coverage → finalize.
// Source money, dates and contract reference come only from the file; this page never sends amounts or source values.
// Nothing is sent to clients, no payment is recorded as Zelle/Stripe and no resources are reserved here.
class ImportHoneyBook extends Page
{
    use WithFileUploads;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;
    protected static ?string $navigationLabel = 'Importar HoneyBook';
    protected static ?int $navigationSort = 90;
    protected static ?string $slug = 'importar';
    protected static ?string $title = 'Importar eventos de HoneyBook';
    protected string $view = 'filament.pages.import-honeybook';

    /** Spanish meaning of each source finding (codes from HoneyBookFutureSource). */
    public const REASONS = [
        'owner_status_review_required' => 'El estado no dice "confirmed": confírmalo tú al importar',
        'package_equation_mismatch' => 'Paquete + transporte − descuento no da el total',
        'balance_equation_mismatch' => 'Total − pagado no da el saldo',
        'payments_equation_mismatch' => 'La suma de los pagos no da lo pagado',
        'source_required' => 'Falta un dato obligatorio',
        'invalid_two_decimal_money' => 'Importe con formato inválido',
        'invalid_or_ambiguous_houston_window' => 'Hora inválida o repetida por cambio de horario',
        'invalid_source_date' => 'Fecha inválida',
        'invalid_source_payment' => 'Un pago no tiene el formato fecha|monto|método',
        'yes_no_required' => 'Contrato firmado debe ser yes o no',
        'invalid_email' => 'Correo inválido', 'invalid_phone' => 'Teléfono inválido', 'invalid_zip' => 'ZIP inválido',
        'contact_missing' => 'Sin correo ni teléfono', 'invalid_name' => 'Nombre inválido', 'field_length' => 'Un campo es demasiado largo',
        'duplicate_source_key' => 'El mismo proyecto aparece dos veces',
    ];

    public const STATUS = ['ready' => 'Lista', 'needs_mapping' => 'Revisar', 'conflict' => 'Diferencia de importes', 'skipped' => 'Omitida'];

    public $csv = null;

    #[Url(as: 'lote')]
    public ?int $batchId = null;

    #[Locked]
    public ?int $rowId = null;

    /** Values displayed when the row panel opened; sent as expected_* so a changed batch/row/catalog/client is refused. */
    #[Locked]
    public array $pinned = [];

    #[Locked]
    public string $key = '';

    public bool $coverageReviewed = false;

    public ?array $data = [];

    public bool $mappingSuggested = false;

    public static function canAccess(): bool
    {
        return parent::canAccess() && app()->environment(['local', 'testing', 'staging']) && (bool) config('waoo.sandbox_imports', false)
            && class_exists(CommitHoneyBookEvent::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->key = (string) Str::uuid();
    }

    public function getSubheading(): ?string
    {
        return 'Sube el CSV de eventos futuros confirmados (24 columnas). El archivo queda privado y se borra al terminar. Los importes se guardan tal como vienen de HoneyBook; nada se cobra ni se envía.';
    }

    // ── 1. Upload ────────────────────────────────────────────────────────────

    public function upload(): void
    {
        $this->validate(['csv' => 'required|file|max:5120'], ['csv.required' => 'Elige el archivo CSV.']);
        try {
            $batch = app(StageHoneyBookFutureEvents::class)->execute(Filament::auth()->user(), $this->csv, $this->key);
        } catch (ValidationException $e) {
            $this->key = (string) Str::uuid();
            throw ValidationException::withMessages(['csv' => self::fileMessage($e)]);
        } catch (RecordWriteRejected|AuthorizationException $e) {
            $this->key = (string) Str::uuid();
            Notification::make()->danger()->title('No se pudo leer el archivo')->body($e->getMessage())->persistent()->send();

            return;
        } finally {
            $this->csv?->delete();
            $this->csv = null;
        }
        $this->key = (string) Str::uuid();
        $this->batchId = $batch->id;
    }

    private static function fileMessage(ValidationException $e): string
    {
        $codes = collect($e->errors())->flatten()->implode(' ');

        return match (true) {
            str_contains($codes, 'header') || str_contains($codes, 'column') => 'El archivo no tiene exactamente las 24 columnas acordadas, en el mismo orden.',
            str_contains($codes, 'encoding') || str_contains($codes, 'utf') => 'El archivo debe estar en UTF-8.',
            default => 'No se pudo leer el archivo ('.$codes.').',
        };
    }

    // ── 2. Batch, rows and coverage (read-only) ──────────────────────────────

    public function batches(): array
    {
        return ImportBatch::query()->orderByDesc('id')->limit(10)->get()
            ->filter(fn ($b) => ($b->profile['source_format'] ?? null) === 'honeybook_future_v1')
            ->map(fn ($b) => ['id' => $b->id, 'status' => $b->status, 'rows' => $b->reconciliation['source_counts']['projects'] ?? 0,
                'when' => CarbonImmutable::parse($b->received_at ?? $b->created_at)->setTimezone(ChicagoTime::TZ)->locale('es')->translatedFormat('D j M, g:i a')])->values()->all();
    }

    public function batch(): ?ImportBatch
    {
        return $this->batchId ? ImportBatch::find($this->batchId) : null;
    }

    public function rows(): array
    {
        if (! $this->batchId) {
            return [];
        }
        $links = ImportedProjectLink::whereIn('project_key', ImportRow::where('import_batch_id', $this->batchId)->whereNotNull('source_key')->pluck('source_key'))->get()->keyBy('project_key');

        return ImportRow::where('import_batch_id', $this->batchId)->orderBy('source_line')->get()->map(function (ImportRow $r) use ($links) {
            $s = $r->source; $n = $r->normalized; $link = $r->source_key ? $links->get($r->source_key) : null;
            $codes = array_column($r->errors ?? [], 'code');
            $current = $link && \App\Models\ImportedProjectRevision::whereKey($link->current_revision_id)->value('source_sha256') === ($n['source_sha256'] ?? null);

            return [
                'id' => $r->id, 'line' => $r->source_line, 'status' => $r->status, 'skip' => $r->skip_reason,
                'when' => isset($n['starts_at']) ? CarbonImmutable::parse($n['starts_at'])->setTimezone(ChicagoTime::TZ)->locale('es')->translatedFormat('D j M Y, g:i a') : ($s['event_date'] ?? '').' '.($s['start_time'] ?? ''),
                'client' => trim(($s['client_first_name'] ?? '').' '.($s['client_last_name'] ?? '')), 'services' => $s['services'] ?? '', 'characters' => $s['characters'] ?? '',
                'total' => Labels::money($n['total_cents'] ?? null, '—'), 'paid' => Labels::money($n['paid_cents'] ?? null, '—'), 'balance' => Labels::money($n['balance_cents'] ?? null, '—'),
                'contract' => ($s['contract_signed'] ?? '') === 'yes' ? 'Firmado en HoneyBook' : 'Sin firmar en HoneyBook',
                'reasons' => collect($codes)->map(fn ($c) => self::REASONS[$c] ?? $c)->unique()->values()->all(),
                'importable' => $r->status !== 'skipped' && array_diff($codes, ['owner_status_review_required']) === [] && ! $current,
                'event_id' => $link?->event_id, 'imported_current' => $current, 'outdated' => $link && ! $current,
            ];
        })->all();
    }

    public function coverage(): ?array
    {
        if (! $this->batchId || in_array($this->batch()?->status, ['discarded', 'cleanup_pending'], true)) {
            return null;
        }
        try {
            return app(ReadImportCoverage::class)->execute(Filament::auth()->user(), $this->batchId);
        } catch (RecordWriteRejected|AuthorizationException) {
            return null;
        }
    }

    // ── 3. Import one row ────────────────────────────────────────────────────

    public function openRow(int $rowId): void
    {
        $batch = $this->batch();
        $row = ImportRow::whereKey($rowId)->where('import_batch_id', $this->batchId)->firstOrFail();
        $this->rowId = $row->id;
        $this->key = (string) Str::uuid();
        $this->pinned = [
            'batch_version' => $batch->version, 'row_version' => $row->version, 'preview_sha256' => $row->preview_sha256,
            // Displayed catalog versions: a package edited after this point is refused instead of silently used.
            'variants' => ServiceVariant::query()->pluck('version', 'id')->map(fn ($v) => (int) $v)->all(),
            'customers' => $this->matches($row)->pluck('version', 'id')->map(fn ($v) => (int) $v)->all(),
        ];
        $suggestion = ImportServiceMapping::where('source_key', MutationKey::hash([$row->source['services'] ?? '', $row->source['characters'] ?? '']))->first();
        $selection = $suggestion?->selection ?? [];
        $matches = $this->matches($row);
        $this->form->fill([
            'customer_mode' => $matches->isNotEmpty() ? 'existing' : 'new', 'customer_id' => $matches->first()?->id, 'locale' => 'es',
            'price_version_ids' => array_map(fn ($s) => (string) $s['price_version_id'], $selection),
            'characters' => collect($selection)->flatMap(fn ($s) => collect($s['characters'] ?? [])->map(fn ($c) => ['price_version_id' => (string) $s['price_version_id'], 'character_id' => (string) $c['character_id'], 'quantity' => $c['quantity']]))->values()->all(),
            'setup_minutes' => null, 'breakdown_minutes' => null, 'source_confirmed' => false, 'source_evidence_reviewed' => false, 'remember_mapping' => true,
        ]);
        $this->mappingSuggested = $suggestion !== null;
    }

    public function closeRow(): void
    {
        $this->rowId = null;
        $this->pinned = [];
    }

    /** Existing clients with the same email or the same last 10 phone digits (never merged automatically). */
    private function matches(ImportRow $row)
    {
        $email = mb_strtolower(trim((string) ($row->normalized['customer_email'] ?? '')));
        $digits = substr(preg_replace('/\D/', '', (string) ($row->normalized['customer_phone'] ?? '')), -10);

        return Customer::query()->orderBy('id')->get(['id', 'name', 'email', 'phone', 'version'])
            ->filter(fn ($c) => ($email !== '' && mb_strtolower(trim((string) $c->email)) === $email)
                || (strlen($digits) === 10 && substr(preg_replace('/\D/', '', (string) $c->phone), -10) === $digits))
            ->take(5)->values();
    }

    public function row(): ?array
    {
        if (! $this->rowId) {
            return null;
        }
        $r = ImportRow::find($this->rowId);
        $s = $r->source; $n = $r->normalized;

        return [
            'line' => $r->source_line, 'source' => $s, 'status_raw' => $s['status'] ?? '', 'confirmed' => ($n['source_status'] ?? null) === 'confirmed',
            'money' => ['Paquete' => $n['package_cents'] ?? null, 'Costo de transporte' => $n['travel_cents'] ?? null, 'Descuento' => $n['discount_cents'] ?? null,
                'Total' => $n['total_cents'] ?? null, 'Pagado' => $n['paid_cents'] ?? null, 'Saldo' => $n['balance_cents'] ?? null],
            'payments' => collect($n['payments'] ?? [])->map(fn ($p) => $p['received_date'].' · '.Labels::money($p['amount_cents']).' · '.$p['method'])->all(),
            'warnings' => array_unique($n['payment_warnings'] ?? []),
            'matches' => $this->matches($r)->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Clienta')->columns(2)->schema([
                Radio::make('customer_mode')->hiddenLabel()->inline()->live()->columnSpanFull()
                    ->options(['existing' => 'Ya es clienta', 'new' => 'Crear clienta nueva con los datos del archivo']),
                Select::make('customer_id')->label('Clienta')->native(false)->required()->columnSpanFull()
                    ->options(fn () => Customer::query()->whereIn('id', array_keys($this->pinned['customers'] ?? []))->pluck('name', 'id'))
                    ->helperText('Solo se muestran fichas con el mismo correo o teléfono. Su ficha no se modifica.')
                    ->visible(fn (Get $get) => $get('customer_mode') === 'existing'),
                Select::make('locale')->label('Idioma de sus mensajes')->native(false)->options(['es' => 'Español', 'en' => 'Inglés'])->required()
                    ->visible(fn (Get $get) => $get('customer_mode') === 'new'),
            ]),
            Section::make('Paquete y personajes en el CRM')->columns(2)->schema([
                Select::make('price_version_ids')->label('Paquete y extras (equivalente en el CRM)')->multiple()->native(false)->searchable()->required()->columnSpanFull()
                    ->options(fn () => EventResource::priceOptions())->live()
                    ->helperText('Solo define qué personal, vestuario y equipo se aparta. Los importes se quedan como en HoneyBook.'),
                Repeater::make('characters')->label('Personajes')->defaultItems(0)->columns(3)->columnSpanFull()->addActionLabel('Agregar personaje')
                    ->visible(fn (Get $get) => EventResource::configurablePrices($get('price_version_ids') ?? []) !== [])
                    ->helperText(fn (Get $get) => EventResource::charactersHint($get('price_version_ids') ?? []))
                    ->schema([
                        Select::make('price_version_id')->label('Para')->native(false)->required()->live()
                            ->options(fn (Get $get) => EventResource::configurablePrices($get('../../price_version_ids') ?? [])),
                        Select::make('character_id')->label('Personaje')->native(false)->required()->searchable()
                            ->options(fn (Get $get) => EventResource::characterOptions($get('price_version_id'))),
                        TextInput::make('quantity')->label('Cuántos')->integer()->minValue(1)->maxValue(20)->default(1)->required(),
                    ]),
                TextInput::make('setup_minutes')->label('Montaje (minutos antes)')->integer()->minValue(0)->maxValue(720)->required(),
                TextInput::make('breakdown_minutes')->label('Desmontaje (minutos después)')->integer()->minValue(0)->maxValue(720)->required(),
                Checkbox::make('remember_mapping')->label('Recordar esta equivalencia para filas con el mismo paquete y personajes')->columnSpanFull(),
            ]),
            Section::make('Confirmación')->schema([
                Checkbox::make('source_confirmed')->accepted()
                    ->label(fn () => ($this->row()['confirmed'] ?? false) ? 'Este evento está confirmado en HoneyBook.'
                        : 'En el archivo el estado dice "'.($this->row()['status_raw'] ?? '').'". Confirmo que este evento está confirmado en HoneyBook.'),
                Checkbox::make('source_evidence_reviewed')->accepted()
                    ->label('Revisé importes, pagos y contrato tal como vienen de HoneyBook. Se guardan como "importado de HoneyBook", no como Zelle o tarjeta verificados.'),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('commit')->footer([
                Actions::make([
                    Action::make('commit')->label('Importar este evento')->color('primary')->submit('commit'),
                    Action::make('cancel')->label('Cancelar')->color('gray')->action('closeRow'),
                ]),
            ])->visible(fn () => $this->rowId !== null),
        ]);
    }

    public function commit(): void
    {
        $s = $this->form->getState();
        $services = collect(EventResource::servicesInput($s))->map(function ($svc) {
            $variant = PriceVersion::whereKey($svc['price_version_id'])->value('service_variant_id');

            return ['price_version_id' => $svc['price_version_id'], 'expected_variant_version' => $this->pinned['variants'][$variant] ?? 0,
                ...(isset($svc['characters']) ? ['characters' => $svc['characters']] : [])];
        })->all();
        $customerId = (int) ($s['customer_id'] ?? 0);
        $input = [
            'expected_batch_version' => $this->pinned['batch_version'], 'expected_row_version' => $this->pinned['row_version'], 'expected_preview_sha256' => $this->pinned['preview_sha256'],
            'source_confirmed' => true, 'source_evidence_reviewed' => true,
            'setup_minutes' => (int) $s['setup_minutes'], 'breakdown_minutes' => (int) $s['breakdown_minutes'], 'services' => $services,
            'remember_mapping' => (bool) ($s['remember_mapping'] ?? false),
            ...($s['customer_mode'] === 'existing'
                ? ['customer_id' => $customerId, 'expected_customer_version' => $this->pinned['customers'][$customerId] ?? 0]
                : ['create_separate_customer' => true, 'new_customer_locale' => $s['locale']]),
        ];
        try {
            $link = app(CommitHoneyBookEvent::class)->execute(Filament::auth()->user(), $this->batchId, $this->rowId, $input, $this->key);
        } catch (ValidationException $e) {
            $this->key = (string) Str::uuid();
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['data.'.self::formPath($k) => $m])->all());
        } catch (RecordWriteRejected|AuthorizationException $e) {
            $this->key = (string) Str::uuid();
            Notification::make()->danger()->title('No se importó')->body($e->getMessage().' Vuelve a abrir la fila para ver lo actual.')->persistent()->send();

            return;
        }
        Notification::make()->success()->title('Evento importado como borrador')->body('Siguiente paso: confirmar personal y equipo desde el evento.')->send();
        $this->closeRow();
        $this->key = (string) Str::uuid();
        unset($link);
    }

    public static function formPath(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'services') => 'price_version_ids',
            $key === 'customer_id', $key === 'expected_customer_version', $key === 'customer' => 'customer_id',
            $key === 'new_customer_locale' => 'locale',
            default => $key,
        };
    }

    // ── 4. Finalize ──────────────────────────────────────────────────────────

    public function finalize(): void
    {
        if (! $this->coverageReviewed) {
            throw ValidationException::withMessages(['coverageReviewed' => 'Confirma que revisaste que el archivo tiene todos los eventos futuros.']);
        }
        try {
            app(FinalizeHoneyBookImport::class)->execute(Filament::auth()->user(), $this->batchId, ['expected_version' => $this->batch()->version, 'source_coverage_reviewed' => true], $this->key);
        } catch (RecordWriteRejected|AuthorizationException|ValidationException $e) {
            $this->key = (string) Str::uuid();
            Notification::make()->danger()->title('No se cerró el lote')->body($e->getMessage())->persistent()->send();

            return;
        }
        $this->key = (string) Str::uuid();
        Notification::make()->success()->title('Lote cerrado')->body('El archivo original se borró. Los eventos y lo pagado en HoneyBook quedan guardados.')->send();
    }
}
