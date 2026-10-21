<?php

namespace App\Filament\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Support\{ChicagoTime, Labels};
use App\Models\HistoryImportBatch;
use App\Services\History\{DiscardHistoricalImport, ReadHistoricalImport, ReadHistoricalRow, StageHistoricalImport};
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\{Locked, Url};
use Livewire\WithFileUploads;

// IMPORT-HISTORY delivery A owner screen (docs/IMPORT-HISTORY-API.md): private upload → counts → paginated rows →
// one row's original fields → discard. Nothing is imported: delivery A has no commit, so there is no import button and
// no "imported" count. Source text is the owner's untrusted file content and is always escaped by Blade.
class ImportHistory extends Page
{
    use WithFileUploads;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;
    protected static ?string $navigationLabel = 'Historial de HoneyBook';
    protected static string|\UnitEnum|null $navigationGroup = 'Operación';
    protected static ?int $navigationSort = 91;
    protected static ?string $slug = 'historial-honeybook';
    protected static ?string $title = 'Historial de HoneyBook';
    protected string $view = 'filament.pages.import-history';

    /** Spanish meaning of each finding (codes from App\Services\History\HistoricalSource). */
    public const ISSUES = [
        'project_id_required' => 'Falta el ID del proyecto',
        'event_date_required' => 'Falta la fecha',
        'duplicate_project_id' => 'El mismo proyecto aparece dos veces',
        'event_date_invalid' => 'Fecha inválida',
        'end_date_invalid' => 'Fecha de fin inválida',
        'start_time_invalid' => 'Hora de inicio inválida',
        'end_time_invalid' => 'Hora de fin inválida',
        'start_time_nonexistent' => 'La hora de inicio no existe (cambio de horario)',
        'end_time_nonexistent' => 'La hora de fin no existe (cambio de horario)',
        'start_time_ambiguous' => 'La hora de inicio se repite (cambio de horario)',
        'end_time_ambiguous' => 'La hora de fin se repite (cambio de horario)',
        'end_not_after_start' => 'Termina antes de empezar',
        'not_safely_past' => 'Es de hoy o futura: no va al historial',
        'client_name_missing' => 'Sin nombre de clienta',
        'email_missing' => 'Sin correo',
        'email_invalid' => 'Correo inválido',
        'source_status_review' => 'Confirmar el estado más adelante',
        'event_type_review' => 'Tipo de evento sin definir',
        'non_usd_or_unknown_currency' => 'Moneda desconocida o no es USD',
        'booked_total_invalid' => 'Total con formato inválido',
    ];

    /** Findings every history row carries until delivery B adds the owner's confirmations; shown once, not per row. */
    public const ROUTINE = ['source_status_review', 'event_type_review'];

    public const COUNTS = ['rows' => 'Filas en el archivo', 'future' => 'De hoy o futuras (no son historial)', 'invalid' => 'Con datos inválidos',
        'missing_email' => 'Sin correo', 'cancelled' => 'Canceladas en HoneyBook'];

    public const SOURCE = ['project_id' => 'ID del proyecto', 'contact_id' => 'ID del contacto', 'client_name' => 'Clienta', 'client_email' => 'Correo',
        'client_phone' => 'Teléfono', 'event_date' => 'Fecha', 'start_time' => 'Inicio', 'end_time' => 'Fin', 'end_date' => 'Fecha de fin',
        'postal_code' => 'ZIP', 'city' => 'Ciudad', 'project_name' => 'Proyecto', 'child_name' => 'Cumpleañero/a', 'lead_source' => 'Cómo nos encontró',
        'celebrant_name'=>'Festejado/a', 'celebrant_age'=>'Edad', 'children_count'=>'Niños', 'setup_area'=>'Interior/exterior', 'event_language'=>'Idioma', 'special_requests'=>'Peticiones especiales',
        'paid_total'=>'Pagado en HoneyBook', 'balance'=>'Saldo en HoneyBook', 'payments'=>'Pagos de origen', 'line_items'=>'Desglose de origen', 'sms_consent_honeybook'=>'Permiso SMS (fuente HoneyBook)', 'lead_source_text'=>'Cómo nos conoció',
        'booked_total' => 'Total', 'currency' => 'Moneda', 'project_status' => 'Estado en HoneyBook', 'archive_reason' => 'Motivo de archivo', 'event_type' => 'Tipo de evento'];

    public $csv = null;

    #[Url(as: 'lote')]
    public ?int $batchId = null;

    #[Url(as: 'pagina')]
    public int $pageNumber = 1;

    #[Locked]
    public ?int $rowId = null;

    /** Stage key: kept across a failed network retry of the same file, renewed after any answer. */
    #[Locked]
    public string $key = '';

    /** Batch version + coverage hash shown when the owner opened "Descartar"; sent as expected_* (never re-read on submit). */
    #[Locked]
    public array $discardPin = [];

    #[Locked]
    public ?string $discardKey = null;

    public string $discardReason = '';

    private ?array $read = null;
    #[Locked] public array $bulkPin = [];
    #[Locked] public ?string $bulkKey = null;
    #[Locked] public array $selectedRow = [];
    #[Locked] public ?string $decisionKey = null;
    public string $newCustomerLocale = 'es';
    public ?int $reviewCustomerId = null;
    public string $reviewReason = '';
    public string $reviewStatus = 'archived_unknown';
    public string $reviewType = 'unknown';

    public function startBulk(int $version, string $coverage, string $plan): void
    {
        $this->bulkPin = ['expected_batch_version'=>$version,'expected_coverage_sha256'=>$coverage,'expected_plan_sha256'=>$plan];
        $this->bulkKey = (string)Str::uuid();
    }
    public function importSafe(): void
    {
        if (!$this->batchId || !$this->bulkPin || !$this->bulkKey) return;
        try {
            $result = app(\App\Services\History\ImportSafeHistoricalRows::class)->execute(Filament::auth()->user(),$this->batchId,
                [...$this->bulkPin,'new_customer_locale'=>$this->newCustomerLocale],$this->bulkKey);
        } catch (RecordWriteRejected|AuthorizationException $e) {
            $this->bulkPin=[]; $this->bulkKey=null; $this->read=null;
            Notification::make()->danger()->title('Revisa de nuevo')->body($e->getMessage())->send(); return;
        }
        $this->bulkPin=[]; $this->bulkKey=null; $this->selectedRow=[]; $this->rowId=null; $this->read=null;
        Notification::make()->success()->title('Historial guardado')->body($result['imported'].' importadas; '.$result['unchanged'].' sin cambios; '.$result['review_required'].' por revisar.')->send();
    }
    public function decideRow(bool $exclude = false): void
    {
        if (!$this->batchId || !$this->rowId || !$this->selectedRow || !$this->decisionKey) return;
        $r=$this->selectedRow;
        $input=['expected_batch_version'=>$r['batch_version'],'expected_row_version'=>$r['version'],'expected_preview_sha256'=>$r['preview_sha256']];
        if ($exclude) $input['owner_reason']=$this->reviewReason;
        else {
            $source=$r['source'];
            $customer=$this->reviewCustomerId
                ? ['mode'=>'link','id'=>$this->reviewCustomerId,'expected_version'=>collect($r['customer_match']['candidates'])->firstWhere('id',$this->reviewCustomerId)['version'] ?? 0]
                : ['mode'=>'create','name'=>$source['client_name'],'email'=>filter_var($source['client_email'],FILTER_VALIDATE_EMAIL)?$source['client_email']:null,
                    'phone'=>\App\Filament\Support\ContactLinks::digits($source['client_phone'])?$source['client_phone']:null,'locale'=>$this->newCustomerLocale];
            if($r['previous'])$input += ['expected_history_version'=>$r['previous']['version'],'expected_revision_id'=>$r['previous']['revision_id'],'expected_diff_sha256'=>$r['previous']['diff_sha256']];
            $input += ['expected_match_sha256'=>$r['customer_match']['expected_match_sha256'],'customer'=>$customer,
                'source_status_confirmation'=>$this->reviewStatus,'event_type_confirmation'=>$this->reviewType,'owner_note'=>$this->reviewReason ?: null];
        }
        try {
            $service=$exclude ? \App\Services\History\ExcludeHistoricalRow::class : \App\Services\History\CommitHistoricalRow::class;
            app($service)->execute(Filament::auth()->user(),$this->batchId,$this->rowId,$input,$this->decisionKey);
        } catch (RecordWriteRejected|AuthorizationException $e) {
            $this->closeRow(); $this->read=null;
            Notification::make()->danger()->title('Revisa de nuevo')->body($e->getMessage())->send(); return;
        }
        $this->closeRow(); $this->read=null; Notification::make()->success()->title($exclude?'Fila excluida con motivo':'Historial guardado')->send();
    }
    #[Locked] public array $closePin = [];
    #[Locked] public ?string $closeKey = null;
    public function startClose(int $version,string $coverage): void {
        $this->closePin=['expected_batch_version'=>$version,'expected_coverage_sha256'=>$coverage]; $this->closeKey=(string)Str::uuid();
    }
    public function closeBatch(): void {
        if(!$this->batchId || !$this->closePin || !$this->closeKey) return;
        try { app(\App\Services\History\CloseHistoricalImport::class)->execute(Filament::auth()->user(),$this->batchId,$this->closePin,$this->closeKey); }
        catch(RecordWriteRejected|AuthorizationException $e) { Notification::make()->danger()->title('No se cerró')->body($e->getMessage())->send(); }
        $this->closePin=[]; $this->closeKey=null; $this->read=null;
    }


    public static function canAccess(): bool
    {
        return parent::canAccess() && app()->environment(['local', 'testing', 'staging']) && (bool) config('waoo.sandbox_history', false)
            && class_exists(StageHistoricalImport::class) && Filament::auth()->user()?->canAccessPanel(Filament::getPanel('admin'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->key = (string) Str::uuid();
    }

    public function getSubheading(): ?string
    {
        return 'Archivo privado cifrado: importa en bloque las filas sin conflicto y revisa las restantes, sin mover dinero ni enviar mensajes.';
    }

    // ── Upload ───────────────────────────────────────────────────────────────

    public function stageFile(): void
    {
        $this->validate(['csv' => 'required|file|max:5120'], ['csv.required' => 'Elige el archivo CSV.', 'csv.max' => 'El archivo pasa de 5 MB.']);
        try {
            $r = app(StageHistoricalImport::class)->execute(Filament::auth()->user(), $this->csv, $this->key);
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
        $this->open($r['batch_id']);
    }

    private static function fileMessage(ValidationException $e): string
    {
        $codes = collect($e->errors())->flatten()->implode(' ');

        return match (true) {
            str_contains($codes, 'header') => 'El archivo no tiene exactamente las 19 columnas del historial, en el mismo orden.',
            str_contains($codes, 'rows_required') => 'El archivo no tiene filas.',
            str_contains($codes, 'encoding') || str_contains($codes, 'utf') => 'El archivo debe estar en UTF-8.',
            default => 'No se pudo leer el archivo ('.$codes.').',
        };
    }

    // ── Batch, rows (read-only) ─────────────────────────────────────────────

    public function open(?int $batchId): void
    {
        $this->bulkPin=[]; $this->bulkKey=null; $this->closePin=[]; $this->closeKey=null; $this->selectedRow=[]; $this->decisionKey=null;
        $this->batchId = $batchId;
        $this->pageNumber = 1;
        $this->rowId = null;
        $this->cancelDiscard();
        $this->read = null;
    }

    public function updatedBatchId(): void
    {
        $this->open($this->batchId);
    }

    /** Batches still under review (metadata only: no counts or contents outside the service read). */
    public function batches(): array
    {
        return HistoryImportBatch::query()->whereIn('state', ['reviewing','closed'])->orderByDesc('id')->limit(10)->get(['id', 'created_at'])
            ->map(fn ($b) => ['id' => $b->id, 'when' => CarbonImmutable::parse($b->created_at)->setTimezone(ChicagoTime::TZ)->locale('es')->translatedFormat('D j M, g:i a')])
            ->all();
    }

    /** Current service read of the open batch: ['ok' => array] or ['error' => message, 'gone' => bool]. */
    public function view(): ?array
    {
        if (! $this->batchId) {
            return null;
        }
        if ($this->read !== null) {
            return $this->read;
        }
        try {
            $r = app(ReadHistoricalImport::class)->execute(Filament::auth()->user(), $this->batchId, ['page' => max(1, $this->pageNumber), 'per_page' => 25]);
            $this->pageNumber = $r['page']['number'];

            return $this->read = ['ok' => $r];
        } catch (RecordWriteRejected $e) {
            return $this->read = ['error' => $e->status === 410 ? 'Este archivo ya se descartó.' : $e->getMessage(), 'gone' => $e->status === 410];
        } catch (AuthorizationException $e) {
            return $this->read = ['error' => $e->getMessage(), 'gone' => false];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->read = ['error' => 'No existe ese archivo.', 'gone' => true];
        }
    }

    public function goPage(int $page): void
    {
        $this->pageNumber = max(1, $page);
        $this->rowId = null; $this->selectedRow=[]; $this->decisionKey=null;
        $this->read = null;
    }

    public function showRow(int $rowId): void
    {
        $this->rowId = $rowId;
        $this->selectedRow = app(ReadHistoricalRow::class)->execute(Filament::auth()->user(), $this->batchId, $rowId);
        $this->decisionKey=(string)Str::uuid(); $this->reviewReason='';
        $this->reviewStatus=$this->selectedRow['normalized']['status']; $this->reviewType=$this->selectedRow['normalized']['event_type'];
        $this->reviewCustomerId = count($this->selectedRow['customer_match']['candidates']) === 1 ? $this->selectedRow['customer_match']['candidates'][0]['id'] : null;
    }

    public function closeRow(): void
    {
        $this->rowId = null; $this->selectedRow=[]; $this->decisionKey=null;
    }

    public function row(): ?array
    {
        if (! $this->batchId || ! $this->rowId) {
            return null;
        }
        try {
            \App\Services\History\HistoryAccess::actor(Filament::auth()->user());
            return ['ok' => $this->selectedRow];
        } catch (RecordWriteRejected|AuthorizationException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ['error' => 'Esa fila no es de este archivo.'];
        }
    }

    public static function issues(array $codes, bool $withRoutine = false): array
    {
        return collect($codes)->reject(fn ($c) => ! $withRoutine && in_array($c, self::ROUTINE, true))
            ->map(fn ($c) => self::ISSUES[$c] ?? $c)->values()->all();
    }

    public static function displaySource(string $field,string $value):string {
        if($field!=='payments')return $value;
        return implode(';',array_map(function($entry){$parts=explode('|',$entry);if(count($parts)===3)$parts[2]=\App\Services\Imports\HoneyBookPaymentLabels::current($parts[2])['display_method'];return implode('|',$parts);},explode(';',$value)));
    }

    public static function money(?int $cents, ?string $currency): string
    {
        return $cents === null ? 'Sin dato' : Labels::money($cents).($currency && $currency !== 'USD' ? ' '.$currency : '');
    }

    // ── Discard ──────────────────────────────────────────────────────────────

    /** Pins exactly the version/hash on screen; a later change in the batch makes the discard fail instead of using fresh values. */
    public function startDiscard(int $version, string $coverage): void
    {
        $this->discardPin = ['version' => $version, 'coverage' => $coverage];
        $this->discardKey = (string) Str::uuid();
        $this->discardReason = '';
    }

    public function cancelDiscard(): void
    {
        $this->discardPin = [];
        $this->discardKey = null;
        $this->discardReason = '';
    }

    public function discard(): void
    {
        if (! $this->batchId || ! $this->discardPin || ! $this->discardKey) {
            return;
        }
        $this->validate(['discardReason' => 'required|string|min:5|max:1000'],
            ['discardReason.required' => 'Escribe por qué lo descartas.', 'discardReason.min' => 'Escribe al menos 5 letras.']);
        try {
            app(DiscardHistoricalImport::class)->execute(Filament::auth()->user(), $this->batchId, [
                'expected_batch_version' => $this->discardPin['version'],
                'expected_coverage_sha256' => $this->discardPin['coverage'],
                'owner_reason' => $this->discardReason,
            ], $this->discardKey);
        } catch (RecordWriteRejected|AuthorizationException $e) {
            $this->cancelDiscard();
            $this->read = null;
            Notification::make()->danger()->title('No se descartó')->body($e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()->title('Archivo descartado')->body('Se borró su contenido de la base de datos. Las copias de seguridad no se borran con esto.')->send();
        $this->open(null);
    }
}
